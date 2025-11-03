-- Migration: Update employment contracts table to add manual fields
-- Description: Adds employee_name, id_card, province, and contract_type columns for manual contract generation
-- Instructions: Execute each ALTER TABLE statement one by one. If a column already exists, skip that statement.

-- Step 1: Add employee_name column (skip if already exists)
ALTER TABLE employment_contracts ADD COLUMN employee_name VARCHAR(255) NULL AFTER employee_id;

-- Step 2: Add id_card column (skip if already exists)
ALTER TABLE employment_contracts ADD COLUMN id_card VARCHAR(50) NULL AFTER employee_name;

-- Step 3: Add province column (skip if already exists)
ALTER TABLE employment_contracts ADD COLUMN province VARCHAR(100) NULL AFTER id_card;

-- Step 4: Add contract_type column (skip if already exists)
ALTER TABLE employment_contracts ADD COLUMN contract_type VARCHAR(50) NOT NULL DEFAULT 'TRABAJO' AFTER city;

-- Step 5: Make employee_id nullable
ALTER TABLE employment_contracts MODIFY COLUMN employee_id INT UNSIGNED NULL;
