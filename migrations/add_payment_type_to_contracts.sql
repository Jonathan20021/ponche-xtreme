-- Add payment_type column to employment_contracts table
-- This column specifies whether the salary is hourly or monthly

ALTER TABLE employment_contracts 
ADD COLUMN payment_type VARCHAR(20) DEFAULT 'por_hora' 
COMMENT 'Tipo de pago: por_hora o mensual'
AFTER salary;

-- Update existing records to have a default value (por_hora ya que la mayoría de empleados cobran por hora)
UPDATE employment_contracts 
SET payment_type = 'por_hora' 
WHERE payment_type IS NULL;
