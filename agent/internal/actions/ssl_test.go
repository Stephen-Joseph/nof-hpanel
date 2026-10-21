package actions

import (
	"context"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"math/big"
	"testing"
	"time"
)

// generateTestCertificate generates a temporary in-memory RSA key and self-signed certificate for testing.
func generateTestCertificate(domain string) (certPEM string, keyPEM string, err error) {
	priv, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		return "", "", err
	}

	notBefore := time.Now().Add(-1 * time.Hour)
	notAfter := notBefore.Add(365 * 24 * time.Hour)

	serialNumber, _ := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 128))

	template := x509.Certificate{
		SerialNumber: serialNumber,
		Subject: pkix.Name{
			Organization: []string{"HPanel Test Org"},
			CommonName:   domain,
		},
		NotBefore:             notBefore,
		NotAfter:              notAfter,
		KeyUsage:              x509.KeyUsageKeyEncipherment | x509.KeyUsageDigitalSignature,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
		DNSNames:              []string{domain, "www." + domain},
	}

	derBytes, err := x509.CreateCertificate(rand.Reader, &template, &template, &priv.PublicKey, priv)
	if err != nil {
		return "", "", err
	}

	certOut := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: derBytes})
	keyOut := pem.EncodeToMemory(&pem.Block{Type: "RSA PRIVATE KEY", Bytes: x509.MarshalPKCS1PrivateKey(priv)})

	return string(certOut), string(keyOut), nil
}

func TestValidateEmail(t *testing.T) {
	valid := []string{"admin@example.com", "user.name+tag@sub.domain.org"}
	for _, e := range valid {
		if err := ValidateEmail(e); err != nil {
			t.Errorf("expected valid email for '%s', got error: %v", e, err)
		}
	}

	invalid := []string{"", "plainaddress", "@missinguser.com", "user@.com"}
	for _, e := range invalid {
		if err := ValidateEmail(e); err == nil {
			t.Errorf("expected error for invalid email '%s', got nil", e)
		}
	}
}

func TestValidateKeyPairMatch(t *testing.T) {
	certPEM, keyPEM, err := generateTestCertificate("mysite.com")
	if err != nil {
		t.Fatalf("failed to generate test cert: %v", err)
	}

	parsed, err := ValidateKeyPair(certPEM, keyPEM, "")
	if err != nil {
		t.Fatalf("expected valid keypair, got: %v", err)
	}

	if parsed.Subject != "mysite.com" {
		t.Errorf("expected subject mysite.com, got %s", parsed.Subject)
	}
	if parsed.DaysRemaining <= 300 {
		t.Errorf("expected > 300 days remaining, got %d", parsed.DaysRemaining)
	}
	if parsed.IsExpired {
		t.Errorf("certificate should not be expired")
	}

	// Mismatched private key
	_, otherKeyPEM, _ := generateTestCertificate("other.com")
	_, err = ValidateKeyPair(certPEM, otherKeyPEM, "")
	if err == nil {
		t.Errorf("expected mismatch error when pairing with different private key, got nil")
	}

	// Corrupted PEM
	_, err = ValidateKeyPair("corrupt-cert", keyPEM, "")
	if err == nil {
		t.Errorf("expected error for corrupt cert PEM, got nil")
	}
}

func TestSslIssueActionDryRun(t *testing.T) {
	action := NewSslIssueAction()

	payload, _ := json.Marshal(SslIssuePayload{
		Domain:   "example.org",
		Email:    "ssl-admin@example.org",
		AltNames: []string{"www.example.org"},
		AgreeTOS: true,
	})

	if err := action.Validate(payload); err != nil {
		t.Fatalf("validation failed: %v", err)
	}

	res, err := action.Execute(context.Background(), payload, true)
	if err != nil {
		t.Fatalf("execute failed: %v", err)
	}

	if !res.Success {
		t.Fatalf("expected success, got error: %s", res.Error)
	}
	if res.Data["domain"] != "example.org" {
		t.Errorf("expected domain example.org, got %v", res.Data["domain"])
	}
	if res.Data["type"] != "lets_encrypt" {
		t.Errorf("expected type lets_encrypt, got %v", res.Data["type"])
	}
}

func TestSslCustomActionDryRun(t *testing.T) {
	action := NewSslCustomAction()

	certPEM, keyPEM, err := generateTestCertificate("secure.app")
	if err != nil {
		t.Fatalf("failed to generate test cert: %v", err)
	}

	payload, _ := json.Marshal(SslCustomPayload{
		Domain:      "secure.app",
		Certificate: certPEM,
		PrivateKey:  keyPEM,
	})

	if err := action.Validate(payload); err != nil {
		t.Fatalf("validation failed: %v", err)
	}

	res, err := action.Execute(context.Background(), payload, true)
	if err != nil {
		t.Fatalf("execute failed: %v", err)
	}

	if !res.Success {
		t.Fatalf("expected success, got error: %s", res.Error)
	}
	if res.Data["subject"] != "secure.app" {
		t.Errorf("expected subject secure.app, got %v", res.Data["subject"])
	}
}

func TestSslRenewAndRevokeDryRun(t *testing.T) {
	renewAction := NewSslRenewAction()
	renewPayload, _ := json.Marshal(SslRenewPayload{Domain: "renewsite.com", Force: true})

	if err := renewAction.Validate(renewPayload); err != nil {
		t.Fatalf("renew validation failed: %v", err)
	}
	renewRes, err := renewAction.Execute(context.Background(), renewPayload, true)
	if err != nil || !renewRes.Success {
		t.Fatalf("renew execute failed: %v (%v)", err, renewRes.Error)
	}

	revokeAction := NewSslRevokeAction()
	revokePayload, _ := json.Marshal(SslRevokePayload{Domain: "renewsite.com"})
	if err := revokeAction.Validate(revokePayload); err != nil {
		t.Fatalf("revoke validation failed: %v", err)
	}
	revokeRes, err := revokeAction.Execute(context.Background(), revokePayload, true)
	if err != nil || !revokeRes.Success {
		t.Fatalf("revoke execute failed: %v (%v)", err, revokeRes.Error)
	}
}

