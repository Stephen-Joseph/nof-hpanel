# Domain & SSL Regressions Summary

1. commit fc43f61: Initial domain verification (CNAME/TXT) and SSL certbot issuance actions (ssl.issue, DomainVerificationService, Livewire domain cards).
2. commit b9a3e0f: Implemented base vhost templates, default-deny vhost, and atomic step execution in the Go agent.
3. commit d0bae90: Introduced atomic file configuration writes and rollbacks applied to nginx and vhost deployment.
4. commit fd4c387: Modified vhost template try_files rules and added app profile selector for domains.
5. commit 3198bed: Refined php-generic try_files and app profile rendering in vhost templates.
6. commit f898bb6: Introduced customer web server structured settings affecting Nginx vhost generation.
7. commit 234e4f5: Added htaccess compatibility include blocks inside Nginx vhost template generation.
8. commit 5c91dc1: Added is_primary column, PrimaryDomainService, BackfillPrimaryDomainsCommand, and HPANEL MANAGED/CUSTOM vhost markers.
9. commit 6aa4875: Added per-domain advanced Nginx vhost editing, validation, and reload/rollback mechanisms.
10. commit da31a34: Summarized completed work across primary domain provisioning and Nginx custom block handling.
