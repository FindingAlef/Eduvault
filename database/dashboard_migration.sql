-- Dashboard Features Migration Script
-- Run this in phpMyAdmin to add dashboard features to your existing database
-- Make sure to select the 'eduvault' database before running

USE eduvault;

-- 1. Add profile fields to users table
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS institution VARCHAR(255) NULL AFTER email;

ALTER TABLE users 
ADD COLUMN IF NOT EXISTS bio TEXT NULL AFTER institution;

-- Add profile fields to administrator accounts
ALTER TABLE admins
ADD COLUMN IF NOT EXISTS institution VARCHAR(255) NULL AFTER email;

ALTER TABLE admins
ADD COLUMN IF NOT EXISTS bio TEXT NULL AFTER institution;

-- 2. Add likes column to notes table (if it doesn't exist)
ALTER TABLE notes 
ADD COLUMN IF NOT EXISTS likes INT DEFAULT 0 AFTER downloads;

-- 3. Create saved_notes table
CREATE TABLE IF NOT EXISTS saved_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    note_id INT NOT NULL,
    saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_saved (user_id, note_id),
    INDEX idx_saved_user (user_id),
    INDEX idx_saved_note (note_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create liked_notes table
CREATE TABLE IF NOT EXISTS liked_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    note_id INT NOT NULL,
    liked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_liked (user_id, note_id),
    INDEX idx_liked_user (user_id),
    INDEX idx_liked_note (note_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create administrator saved/liked-note tables
CREATE TABLE IF NOT EXISTS admin_saved_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    note_id INT NOT NULL,
    saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_admin_saved (admin_id, note_id),
    INDEX idx_admin_saved_admin (admin_id),
    INDEX idx_admin_saved_note (note_id),
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_liked_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    note_id INT NOT NULL,
    liked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_admin_liked (admin_id, note_id),
    INDEX idx_admin_liked_admin (admin_id),
    INDEX idx_admin_liked_note (note_id),
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If your MySQL version does not support ADD COLUMN IF NOT EXISTS, check each
-- column first and run only the corresponding ALTER TABLE statement(s).
