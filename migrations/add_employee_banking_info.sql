-- Migration: Add bank account type column to employees table
-- Date: 2025-11-03

-- Add bank_account_type column (other banking columns already exist)
ALTER TABLE employees
ADD COLUMN bank_account_type VARCHAR(50) DEFAULT NULL COMMENT 'AHORROS_DOP, AHORROS_USD, CORRIENTE_DOP, CORRIENTE_USD' AFTER bank_account_number;
