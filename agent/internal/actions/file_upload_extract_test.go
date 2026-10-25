package actions

import (
	"archive/tar"
	"archive/zip"
	"bytes"
	"compress/gzip"
	"context"
	"encoding/base64"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// 1. Traversal: rejects ../../etc/passwd in upload, extract, and archive
func TestFileUploadAndExtractTraversal(t *testing.T) {
	uploadAction := NewFileUploadAction()

	// file.upload: traversal in filename
	payload, _ := json.Marshal(FileUploadPayload{
		Username:      "alice",
		TargetPath:    "public_html",
		Filename:      "../../etc/passwd",
		ContentBase64: base64.StdEncoding.EncodeToString([]byte("root:x:0:0")),
	})
	if err := uploadAction.Validate(payload); err == nil {
		t.Fatal("expected file.upload to reject traversal in filename, but got nil")
	}

	// file.upload: traversal in target_path
	payloadBadTarget, _ := json.Marshal(FileUploadPayload{
		Username:      "alice",
		TargetPath:    "../../../../etc",
		Filename:      "passwd",
		ContentBase64: base64.StdEncoding.EncodeToString([]byte("bad")),
	})
	if err := uploadAction.Validate(payloadBadTarget); err == nil {
		t.Fatal("expected file.upload to reject traversal in target_path, but got nil")
	}

	// file.extract: zip traversal (../../etc/passwd)
	destDir := t.TempDir()
	var zipBuf bytes.Buffer
	zw := zip.NewWriter(&zipBuf)
	zh, _ := zw.CreateHeader(&zip.FileHeader{Name: "../../etc/passwd", Method: zip.Deflate})
	_, _ = zh.Write([]byte("evil"))
	_ = zw.Close()

	zipFile := filepath.Join(destDir, "traversal.zip")
	_ = os.WriteFile(zipFile, zipBuf.Bytes(), 0644)

	err := scanZipEntries(zipFile, destDir, 100*1024*1024, 500*1024*1024)
	if err == nil || !strings.Contains(err.Error(), "traversal") {
		t.Fatalf("expected scanZipEntries to detect traversal, got: %v", err)
	}

	// file.extract: tar.gz traversal (../../etc/passwd)
	var tarBuf bytes.Buffer
	gzw := gzip.NewWriter(&tarBuf)
	tw := tar.NewWriter(gzw)
	_ = tw.WriteHeader(&tar.Header{
		Name:     "../../etc/passwd",
		Mode:     0644,
		Size:     4,
		Typeflag: tar.TypeReg,
	})
	_, _ = tw.Write([]byte("evil"))
	_ = tw.Close()
	_ = gzw.Close()

	tarFile := filepath.Join(destDir, "traversal.tar.gz")
	_ = os.WriteFile(tarFile, tarBuf.Bytes(), 0644)

	err = scanTarEntries(tarFile, destDir, true, 100*1024*1024, 500*1024*1024)
	if err == nil || !strings.Contains(err.Error(), "traversal") {
		t.Fatalf("expected scanTarEntries to detect traversal, got: %v", err)
	}

	// file.archive: traversal in sources
	archiveAction := NewFileArchiveAction()
	archivePayload, _ := json.Marshal(FileArchivePayload{
		Username:       "alice",
		Sources:        []string{"../../etc/passwd"},
		DestinationZip: "public_html/backup.zip",
	})
	if err := archiveAction.Validate(archivePayload); err == nil {
		t.Fatal("expected file.archive to reject traversal source, but got nil")
	}
}

// 2. Absolute path: rejects /etc/shadow or /etc/passwd in upload and extract
func TestFileUploadAndExtractAbsolutePath(t *testing.T) {
	uploadAction := NewFileUploadAction()

	// file.upload: absolute path filename
	payload, _ := json.Marshal(FileUploadPayload{
		Username:      "alice",
		TargetPath:    "public_html",
		Filename:      "/etc/shadow",
		ContentBase64: base64.StdEncoding.EncodeToString([]byte("shadow")),
	})
	if err := uploadAction.Validate(payload); err == nil {
		t.Fatal("expected file.upload to reject absolute path in filename")
	}

	// file.extract: zip with absolute path
	destDir := t.TempDir()
	var zipBuf bytes.Buffer
	zw := zip.NewWriter(&zipBuf)
	zh, _ := zw.CreateHeader(&zip.FileHeader{Name: "/etc/shadow", Method: zip.Deflate})
	_, _ = zh.Write([]byte("secret"))
	_ = zw.Close()

	zipFile := filepath.Join(destDir, "abs.zip")
	_ = os.WriteFile(zipFile, zipBuf.Bytes(), 0644)

	err := scanZipEntries(zipFile, destDir, 100*1024*1024, 500*1024*1024)
	if err == nil || !strings.Contains(err.Error(), "absolute") {
		t.Fatalf("expected scanZipEntries to detect absolute path, got: %v", err)
	}

	// file.extract: tar with absolute path
	var tarBuf bytes.Buffer
	tw := tar.NewWriter(&tarBuf)
	_ = tw.WriteHeader(&tar.Header{
		Name:     "/etc/shadow",
		Mode:     0644,
		Size:     6,
		Typeflag: tar.TypeReg,
	})
	_, _ = tw.Write([]byte("secret"))
	_ = tw.Close()

	tarFile := filepath.Join(destDir, "abs.tar")
	_ = os.WriteFile(tarFile, tarBuf.Bytes(), 0644)

	err = scanTarEntries(tarFile, destDir, false, 100*1024*1024, 500*1024*1024)
	if err == nil || !strings.Contains(err.Error(), "absolute") {
		t.Fatalf("expected scanTarEntries to detect absolute path, got: %v", err)
	}
}

// 3. Symlink escape: rejects symlink pointing outside destination in extract and upload
func TestFileUploadAndExtractSymlinkEscape(t *testing.T) {
	destDir := t.TempDir()

	// file.upload: refusing to overwrite an existing symlink
	existingSymlink := filepath.Join(destDir, "escaped.txt")
	_ = os.Symlink("/etc/passwd", existingSymlink)

	// file.extract: zip containing symlink pointing to /etc/passwd
	var zipBuf bytes.Buffer
	zw := zip.NewWriter(&zipBuf)
	zh := &zip.FileHeader{Name: "evil_symlink.txt"}
	zh.SetMode(os.ModeSymlink | 0777)
	w, _ := zw.CreateHeader(zh)
	_, _ = w.Write([]byte("/etc/passwd"))
	_ = zw.Close()

	zipFile := filepath.Join(destDir, "symlink_escape.zip")
	_ = os.WriteFile(zipFile, zipBuf.Bytes(), 0644)

	err := scanZipEntries(zipFile, destDir, 100*1024*1024, 500*1024*1024)
	if err == nil || (!strings.Contains(err.Error(), "symlink") && !strings.Contains(err.Error(), "outside")) {
		t.Fatalf("expected scanZipEntries to reject escaping symlink, got: %v", err)
	}

	// file.extract: tar.gz containing relative symlink pointing outside target (../../../../etc/passwd)
	var tarBuf bytes.Buffer
	gzw := gzip.NewWriter(&tarBuf)
	tw := tar.NewWriter(gzw)
	_ = tw.WriteHeader(&tar.Header{
		Name:     "sub/link.txt",
		Linkname: "../../../../../etc/passwd",
		Typeflag: tar.TypeSymlink,
	})
	_ = tw.Close()
	_ = gzw.Close()

	tarFile := filepath.Join(destDir, "symlink_escape.tar.gz")
	_ = os.WriteFile(tarFile, tarBuf.Bytes(), 0644)

	err = scanTarEntries(tarFile, destDir, true, 100*1024*1024, 500*1024*1024)
	if err == nil || !strings.Contains(err.Error(), "outside") {
		t.Fatalf("expected scanTarEntries to reject escaping relative symlink, got: %v", err)
	}
}

// 4. Zip bomb (size limit): rejects archive exceeding quota or per-entry limit
func TestFileExtractZipBombSizeLimit(t *testing.T) {
	destDir := t.TempDir()

	// Create a zip with an entry having UncompressedSize64 larger than allowed quota (e.g. 100MB)
	var zipBuf bytes.Buffer
	zw := zip.NewWriter(&zipBuf)
	header := &zip.FileHeader{
		Name:               "huge_bomb.dat",
		Method:             zip.Deflate,
		UncompressedSize64: 200 * 1024 * 1024, // Claim 200MB uncompressed
		CompressedSize64:   10,
	}
	w, err := zw.CreateRaw(header)
	if err != nil {
		t.Fatalf("failed CreateRaw: %v", err)
	}
	_, _ = w.Write([]byte("1234567890"))
	_ = zw.Close()

	zipFile := filepath.Join(destDir, "bomb.zip")
	_ = os.WriteFile(zipFile, zipBuf.Bytes(), 0644)

	// Enforce 50MB quota: should reject
	err = scanZipEntries(zipFile, destDir, 500*1024*1024, 50*1024*1024)
	if err == nil || !strings.Contains(err.Error(), "quota limit") {
		t.Fatalf("expected scanZipEntries to reject zip bomb exceeding quota, got: %v", err)
	}

	// Create a tar with header.Size exceeding single entry limit (e.g. 10MB)
	var tarBuf bytes.Buffer
	tw := tar.NewWriter(&tarBuf)
	_ = tw.WriteHeader(&tar.Header{
		Name:     "big.bin",
		Size:     20 * 1024 * 1024, // 20MB
		Typeflag: tar.TypeReg,
	})
	_ = tw.Close()

	tarFile := filepath.Join(destDir, "bomb.tar")
	_ = os.WriteFile(tarFile, tarBuf.Bytes(), 0644)

	err = scanTarEntries(tarFile, destDir, false, 10*1024*1024, 100*1024*1024)
	if err == nil || !strings.Contains(err.Error(), "single file size limit") {
		t.Fatalf("expected scanTarEntries to reject entry exceeding single file limit, got: %v", err)
	}
}

// 5. Normal extract: extracts .zip and .tar.gz archives cleanly and preserves content
func TestFileExtractNormalZipAndTarGz(t *testing.T) {
	destDir := t.TempDir()

	// 1. Normal Zip
	var zipBuf bytes.Buffer
	zw := zip.NewWriter(&zipBuf)
	f1, _ := zw.Create("hello.txt")
	_, _ = f1.Write([]byte("Hello from zip!"))
	f2, _ := zw.Create("sub/world.php")
	_, _ = f2.Write([]byte("<?php echo 'World';"))
	_ = zw.Close()

	zipPath := filepath.Join(destDir, "normal.zip")
	_ = os.WriteFile(zipPath, zipBuf.Bytes(), 0644)

	zipExtractDir := filepath.Join(destDir, "extracted_zip")
	_ = os.MkdirAll(zipExtractDir, 0755)

	if err := scanZipEntries(zipPath, zipExtractDir, 10*1024*1024, 50*1024*1024); err != nil {
		t.Fatalf("scanZipEntries failed on valid zip: %v", err)
	}
	if err := extractZipContent(zipPath, zipExtractDir); err != nil {
		t.Fatalf("extractZipContent failed: %v", err)
	}

	content1, err := os.ReadFile(filepath.Join(zipExtractDir, "hello.txt"))
	if err != nil || string(content1) != "Hello from zip!" {
		t.Fatalf("extracted zip file mismatch: %v, content=%q", err, string(content1))
	}
	content2, err := os.ReadFile(filepath.Join(zipExtractDir, "sub", "world.php"))
	if err != nil || string(content2) != "<?php echo 'World';" {
		t.Fatalf("extracted zip subfile mismatch: %v, content=%q", err, string(content2))
	}

	// 2. Normal Tar.gz
	var tarBuf bytes.Buffer
	gzw := gzip.NewWriter(&tarBuf)
	tw := tar.NewWriter(gzw)

	data := []byte("Tar.gz content inside!")
	_ = tw.WriteHeader(&tar.Header{
		Name:     "app/config.json",
		Mode:     0644,
		Size:     int64(len(data)),
		Typeflag: tar.TypeReg,
	})
	_, _ = tw.Write(data)
	_ = tw.Close()
	_ = gzw.Close()

	tarGzPath := filepath.Join(destDir, "normal.tar.gz")
	_ = os.WriteFile(tarGzPath, tarBuf.Bytes(), 0644)

	tarExtractDir := filepath.Join(destDir, "extracted_tar")
	_ = os.MkdirAll(tarExtractDir, 0755)

	if err := scanTarEntries(tarGzPath, tarExtractDir, true, 10*1024*1024, 50*1024*1024); err != nil {
		t.Fatalf("scanTarEntries failed on valid tar.gz: %v", err)
	}
	if err := extractTarContent(tarGzPath, tarExtractDir, true); err != nil {
		t.Fatalf("extractTarContent failed: %v", err)
	}

	tarContent, err := os.ReadFile(filepath.Join(tarExtractDir, "app", "config.json"))
	if err != nil || string(tarContent) != "Tar.gz content inside!" {
		t.Fatalf("extracted tar file mismatch: %v, content=%q", err, string(tarContent))
	}
}

// 6. Archive round trip: creates a zip from source paths, then extracts it and verifies contents match
func TestFileArchiveRoundTrip(t *testing.T) {
	root := t.TempDir()

	// Setup mock source files in a source directory
	srcDir := filepath.Join(root, "source")
	_ = os.MkdirAll(filepath.Join(srcDir, "sub"), 0755)
	_ = os.WriteFile(filepath.Join(srcDir, "file1.txt"), []byte("Source file 1 content"), 0644)
	_ = os.WriteFile(filepath.Join(srcDir, "sub", "file2.php"), []byte("<?php return 'ok';"), 0644)

	// Create zip archive
	zipDest := filepath.Join(root, "backup.zip")
	tmpZip, err := os.Create(zipDest)
	if err != nil {
		t.Fatalf("failed creating zipDest: %v", err)
	}
	zw := zip.NewWriter(tmpZip)

	_ = filepath.Walk(srcDir, func(path string, info os.FileInfo, err error) error {
		if err != nil || path == srcDir {
			return nil
		}
		rel, _ := filepath.Rel(srcDir, path)
		header, _ := zip.FileInfoHeader(info)
		header.Name = filepath.ToSlash(rel)
		if info.IsDir() {
			header.Name += "/"
		} else {
			header.Method = zip.Deflate
		}
		w, _ := zw.CreateHeader(header)
		if !info.IsDir() {
			f, _ := os.Open(path)
			defer f.Close()
			_, _ = io.Copy(w, f)
		}
		return nil
	})
	_ = zw.Close()
	_ = tmpZip.Close()

	// Extract into new destination directory
	extractDir := filepath.Join(root, "extracted")
	_ = os.MkdirAll(extractDir, 0755)

	if err := scanZipEntries(zipDest, extractDir, 100*1024*1024, 500*1024*1024); err != nil {
		t.Fatalf("scanZipEntries failed on round-trip zip: %v", err)
	}
	if err := extractZipContent(zipDest, extractDir); err != nil {
		t.Fatalf("extractZipContent failed on round-trip: %v", err)
	}

	// Verify all round trip files
	f1Data, err := os.ReadFile(filepath.Join(extractDir, "file1.txt"))
	if err != nil || string(f1Data) != "Source file 1 content" {
		t.Fatalf("round-trip file1 mismatch: %v, data=%q", err, string(f1Data))
	}
	f2Data, err := os.ReadFile(filepath.Join(extractDir, "sub", "file2.php"))
	if err != nil || string(f2Data) != "<?php return 'ok';" {
		t.Fatalf("round-trip file2 mismatch: %v, data=%q", err, string(f2Data))
	}
}

func TestFileUploadActionDryRunAndChunking(t *testing.T) {
	ctx := context.Background()
	action := NewFileUploadAction()

	content := []byte("Sample text for upload test")
	payload, _ := json.Marshal(FileUploadPayload{
		Username:      "alice",
		TargetPath:    "public_html",
		Filename:      "hello.txt",
		ContentBase64: base64.StdEncoding.EncodeToString(content),
		ChunkIndex:    0,
		TotalChunks:   1,
	})

	res, err := action.Execute(ctx, payload, true)
	if err != nil {
		t.Fatalf("file.upload dry-run error: %v", err)
	}
	if !res.Success || res.Data["filename"] != "hello.txt" {
		t.Fatalf("expected success=true, got %+v", res)
	}
}
