-- Upgrade PR3: allow admin to revoke a certification (contact + package)
CREATE TABLE IF NOT EXISTS certification_revocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contact_id INT NOT NULL,
  package_id INT NOT NULL,
  revoked_by_user_id INT NULL,
  revoked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reason VARCHAR(255) NULL,

  UNIQUE KEY uq_cert_revocation_contact_package (contact_id, package_id),
  INDEX idx_cert_revoked_at (revoked_at),

  CONSTRAINT fk_cert_rev_contact
    FOREIGN KEY (contact_id) REFERENCES contacts(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_cert_rev_package
    FOREIGN KEY (package_id) REFERENCES packages(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_cert_rev_admin
    FOREIGN KEY (revoked_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
);
