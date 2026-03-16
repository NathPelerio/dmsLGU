-- Add stamp settings for users and send metadata.

ALTER TABLE users
    ADD COLUMN stamp LONGTEXT NULL AFTER signature,
    ADD COLUMN stamp_width_pct DECIMAL(5,2) NOT NULL DEFAULT 18.00 AFTER stamp,
    ADD COLUMN stamp_x_pct DECIMAL(5,2) NOT NULL DEFAULT 82.00 AFTER stamp_width_pct,
    ADD COLUMN stamp_y_pct DECIMAL(5,2) NOT NULL DEFAULT 84.00 AFTER stamp_x_pct;

ALTER TABLE sent_to_super_admin
    ADD COLUMN stamp_image LONGTEXT NULL AFTER file_name,
    ADD COLUMN stamp_width_pct DECIMAL(5,2) NOT NULL DEFAULT 18.00 AFTER stamp_image,
    ADD COLUMN stamp_x_pct DECIMAL(5,2) NOT NULL DEFAULT 82.00 AFTER stamp_width_pct,
    ADD COLUMN stamp_y_pct DECIMAL(5,2) NOT NULL DEFAULT 84.00 AFTER stamp_x_pct;

ALTER TABLE super_admin_notifications
    ADD COLUMN stamp_image LONGTEXT NULL AFTER file_name,
    ADD COLUMN stamp_width_pct DECIMAL(5,2) NOT NULL DEFAULT 18.00 AFTER stamp_image,
    ADD COLUMN stamp_x_pct DECIMAL(5,2) NOT NULL DEFAULT 82.00 AFTER stamp_width_pct,
    ADD COLUMN stamp_y_pct DECIMAL(5,2) NOT NULL DEFAULT 84.00 AFTER stamp_x_pct;
