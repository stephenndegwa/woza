-- WHMCS Woza M-pesa Gateway - Database Schema
-- These tables are created automatically by the gateway
-- This file is provided for reference only

-- Transaction tracking table
CREATE TABLE IF NOT EXISTS `mod_mpesa_transactions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `invoice_id` int(11) NOT NULL,
    `checkout_request_id` varchar(255) DEFAULT NULL,
    `merchant_request_id` varchar(255) DEFAULT NULL,
    `phone_number` varchar(20) NOT NULL,
    `amount` decimal(10,2) NOT NULL,
    `status` varchar(50) DEFAULT 'pending',
    `response_code` varchar(10) DEFAULT NULL,
    `response_description` text DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `invoice_id` (`invoice_id`),
    KEY `checkout_request_id` (`checkout_request_id`),
    KEY `status` (`status`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- C2B confirmation table
CREATE TABLE IF NOT EXISTS `mod_mpesa_c2b_confirmations` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `trans_id` varchar(255) NOT NULL,
    `trans_time` varchar(50) NOT NULL,
    `trans_amount` decimal(10,2) NOT NULL,
    `business_short_code` varchar(20) NOT NULL,
    `bill_ref_number` varchar(255) NOT NULL,
    `invoice_number` varchar(255) DEFAULT NULL,
    `msisdn` varchar(20) NOT NULL,
    `first_name` varchar(100) DEFAULT NULL,
    `middle_name` varchar(100) DEFAULT NULL,
    `last_name` varchar(100) DEFAULT NULL,
    `processed` tinyint(1) DEFAULT 0,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `trans_id` (`trans_id`),
    KEY `bill_ref_number` (`bill_ref_number`),
    KEY `processed` (`processed`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Offline payment submissions
CREATE TABLE IF NOT EXISTS `mod_mpesa_offline_payments` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `invoice_id` int(11) NOT NULL,
    `phone_number` varchar(20) NOT NULL,
    `amount` decimal(10,2) NOT NULL,
    `transaction_code` varchar(255) DEFAULT NULL,
    `status` varchar(50) DEFAULT 'pending',
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `invoice_id` (`invoice_id`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
