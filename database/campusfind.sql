CREATE DATABASE IF NOT EXISTS campusfind_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE campusfind_db;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('student','staff','admin') NOT NULL DEFAULT 'student',
  matric_number VARCHAR(40) NULL UNIQUE,
  account_status ENUM('active','restricted') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE categories (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT IGNORE INTO categories(category_name) VALUES
 ('Electronics'),('Documents'),('Bags'),('Clothing'),('Jewelry'),('Books'),('Accessories'),('Other');

CREATE TABLE lost_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  category_id SMALLINT UNSIGNED NOT NULL,
  item_name VARCHAR(120) NOT NULL,
  description TEXT NOT NULL,
  location VARCHAR(160) NOT NULL,
  date_lost DATE NOT NULL,
  image VARCHAR(255) NULL,
  status ENUM('lost','matched','resolved') NOT NULL DEFAULT 'lost',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lost_user FOREIGN KEY(user_id) REFERENCES users(id),
  CONSTRAINT fk_lost_category FOREIGN KEY(category_id) REFERENCES categories(id),
  INDEX idx_lost_status_category(status,category_id),
  FULLTEXT INDEX ft_lost_text(item_name,description,location)
) ENGINE=InnoDB;

CREATE TABLE found_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  category_id SMALLINT UNSIGNED NOT NULL,
  item_name VARCHAR(120) NOT NULL,
  description TEXT NOT NULL,
  location VARCHAR(160) NOT NULL,
  date_found DATE NOT NULL,
  image VARCHAR(255) NULL,
  status ENUM('available','claimed','returned') NOT NULL DEFAULT 'available',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_found_user FOREIGN KEY(user_id) REFERENCES users(id),
  CONSTRAINT fk_found_category FOREIGN KEY(category_id) REFERENCES categories(id),
  INDEX idx_found_status_category(status,category_id),
  FULLTEXT INDEX ft_found_text(item_name,description,location)
) ENGINE=InnoDB;

CREATE TABLE matches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lost_item_id BIGINT UNSIGNED NOT NULL,
  found_item_id BIGINT UNSIGNED NOT NULL,
  match_score TINYINT UNSIGNED NOT NULL,
  status ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_match_pair(lost_item_id,found_item_id),
  CONSTRAINT fk_match_lost FOREIGN KEY(lost_item_id) REFERENCES lost_items(id),
  CONSTRAINT fk_match_found FOREIGN KEY(found_item_id) REFERENCES found_items(id)
) ENGINE=InnoDB;

CREATE TABLE claims (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_id BIGINT UNSIGNED NOT NULL,
  claimant_id BIGINT UNSIGNED NOT NULL,
  verification_details TEXT NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_claim_match FOREIGN KEY(match_id) REFERENCES matches(id),
  CONSTRAINT fk_claim_user FOREIGN KEY(claimant_id) REFERENCES users(id),
  CONSTRAINT fk_claim_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  message VARCHAR(500) NOT NULL,
  type VARCHAR(40) NOT NULL,
  is_read BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notification_user FOREIGN KEY(user_id) REFERENCES users(id),
  INDEX idx_notification_user_read(user_id,is_read,created_at)
) ENGINE=InnoDB;

CREATE TABLE messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id BIGINT UNSIGNED NOT NULL,
  receiver_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_message_sender FOREIGN KEY(sender_id) REFERENCES users(id),
  CONSTRAINT fk_message_receiver FOREIGN KEY(receiver_id) REFERENCES users(id),
  CONSTRAINT fk_message_match FOREIGN KEY(match_id) REFERENCES matches(id),
  INDEX idx_message_thread(sender_id,receiver_id,created_at)
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  activity VARCHAR(160) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activity_user FOREIGN KEY(user_id) REFERENCES users(id),
  INDEX idx_activity_created(created_at)
) ENGINE=InnoDB;

