package actions

import (
	"crypto/tls"
	"crypto/x509"
	"encoding/pem"
	"errors"
	"fmt"
	"net/mail"
	"strings"
	"time"
)

type ParsedCertInfo struct {
	Issuer        string    `json:"issuer"`
	Subject       string    `json:"subject"`
	SANs          []string  `json:"sans"`
	ValidFrom     time.Time `json:"valid_from"`
	ValidTo       time.Time `json:"valid_to"`
	DaysRemaining int       `json:"days_remaining"`
	IsExpired     bool      `json:"is_expired"`
}

// ValidateEmail checks if an email is reasonably formatted.
func ValidateEmail(email string) error {
	trimmed := strings.TrimSpace(email)
	if trimmed == "" {
		return errors.New("email address is required")
	}
	_, err := mail.ParseAddress(trimmed)
	if err != nil {
		return fmt.Errorf("invalid email address '%s': %w", email, err)
	}
	return nil
}

// ValidateKeyPair validates that a PEM certificate and PEM private key parse correctly and match.
func ValidateKeyPair(certPEM, keyPEM, chainPEM string) (*ParsedCertInfo, error) {
	if strings.TrimSpace(certPEM) == "" {
		return nil, errors.New("certificate PEM content cannot be empty")
	}
	if strings.TrimSpace(keyPEM) == "" {
		return nil, errors.New("private key PEM content cannot be empty")
	}

	fullCertPEM := strings.TrimSpace(certPEM)
	if strings.TrimSpace(chainPEM) != "" {
		fullCertPEM = fullCertPEM + "\n" + strings.TrimSpace(chainPEM)
	}

	// Verify the pair matches cryptographically
	_, err := tls.X509KeyPair([]byte(fullCertPEM), []byte(keyPEM))
	if err != nil {
		return nil, fmt.Errorf("certificate and private key do not match or are invalid: %w", err)
	}

	// Decode and parse the first x509 certificate to extract metadata
	block, _ := pem.Decode([]byte(certPEM))
	if block == nil || block.Type != "CERTIFICATE" {
		return nil, errors.New("failed to decode certificate PEM block")
	}

	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return nil, fmt.Errorf("failed to parse x509 certificate: %w", err)
	}

	now := time.Now()
	daysRemaining := int(cert.NotAfter.Sub(now).Hours() / 24)
	isExpired := now.After(cert.NotAfter)

	issuer := cert.Issuer.CommonName
	if issuer == "" && len(cert.Issuer.Organization) > 0 {
		issuer = cert.Issuer.Organization[0]
	}

	return &ParsedCertInfo{
		Issuer:        issuer,
		Subject:       cert.Subject.CommonName,
		SANs:          cert.DNSNames,
		ValidFrom:     cert.NotBefore,
		ValidTo:       cert.NotAfter,
		DaysRemaining: daysRemaining,
		IsExpired:     isExpired,
	}, nil
}

// ParseCertificateFile parses a certificate PEM file on disk and returns parsed metadata.
func ParseCertificateBytes(certBytes []byte) (*ParsedCertInfo, error) {
	block, _ := pem.Decode(certBytes)
	if block == nil {
		return nil, errors.New("failed to decode certificate PEM")
	}

	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return nil, fmt.Errorf("failed to parse certificate: %w", err)
	}

	now := time.Now()
	daysRemaining := int(cert.NotAfter.Sub(now).Hours() / 24)

	issuer := cert.Issuer.CommonName
	if issuer == "" && len(cert.Issuer.Organization) > 0 {
		issuer = cert.Issuer.Organization[0]
	}

	return &ParsedCertInfo{
		Issuer:        issuer,
		Subject:       cert.Subject.CommonName,
		SANs:          cert.DNSNames,
		ValidFrom:     cert.NotBefore,
		ValidTo:       cert.NotAfter,
		DaysRemaining: daysRemaining,
		IsExpired:     now.After(cert.NotAfter),
	}, nil
}
