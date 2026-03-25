SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `civicrm_contactinfo_dedupe_priority`;
DROP TABLE IF EXISTS `civicrm_contactinfo_dedupe_audit_log`;

SET FOREIGN_KEY_CHECKS=1;

-- Priority ranking for location types (single global ranking)
CREATE TABLE `civicrm_contactinfo_dedupe_priority` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique Priority ID',
  `location_type_id` int unsigned NOT NULL COMMENT 'FK to LocationType',
  `priority` int NOT NULL DEFAULT 0 COMMENT 'Priority rank (lower number = higher priority)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `UI_location_type_id` (`location_type_id`),
  UNIQUE KEY `UI_priority` (`priority`),
  CONSTRAINT FK_civicrm_contactinfo_dedupe_priority_location_type_id
    FOREIGN KEY (`location_type_id`) REFERENCES `civicrm_location_type`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Audit log tracking all merge/delete actions
CREATE TABLE `civicrm_contactinfo_dedupe_audit_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique AuditLog ID',
  `entity_type` varchar(32) NOT NULL COMMENT 'Entity type: address, email, or phone',
  `contact_id` int unsigned NOT NULL COMMENT 'FK to Contact',
  `kept_entity_id` int unsigned NOT NULL COMMENT 'ID of the record that was kept',
  `removed_entity_id` int unsigned NOT NULL COMMENT 'ID of the record that was removed',
  `status` varchar(16) NOT NULL COMMENT 'Status: changed or removed',
  `field_changes` text COMMENT 'JSON-encoded field changes (old and new values)',
  `created_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the action was performed',
  `created_by` int unsigned COMMENT 'FK to Contact who performed the action',
  PRIMARY KEY (`id`),
  INDEX `IX_entity_type` (`entity_type`),
  INDEX `IX_contact_id` (`contact_id`),
  INDEX `IX_created_date` (`created_date`),
  CONSTRAINT FK_civicrm_contactinfo_dedupe_audit_log_contact_id
    FOREIGN KEY (`contact_id`) REFERENCES `civicrm_contact`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
