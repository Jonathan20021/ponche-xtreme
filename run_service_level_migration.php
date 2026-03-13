<?php
/**
 * Service Level Calculator Migration Script
 * Executes the database migration for the service level calculator table
 */

require_once __DIR__ . '/db.php';

echo "=== Service Level Calculator Migration ===\n\n";

try {
    // Read migration file
    $migrationFile = __DIR__ . '/migrations/add_service_level_calculator.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }
    
    echo "Reading migration file...\n";
    $sql = file_get_contents($migrationFile);
    
    if (empty($sql)) {
        throw new Exception("Migration file is empty");
    }
    
    // Check if table already exists
    echo "Checking if table already exists...\n";
    $checkStmt = $pdo->query("SHOW TABLES LIKE 'service_level_calculations'");
    $tableExists = $checkStmt->rowCount() > 0;
    
    if ($tableExists) {
        echo "⚠️  Table 'service_level_calculations' already exists!\n";
        echo "\nOptions:\n";
        echo "1. Skip migration (table already exists)\n";
        echo "2. Drop and recreate table (WARNING: will delete all data)\n";
        echo "3. Exit\n\n";
        echo "Choose option (1-3): ";
        
        $handle = fopen("php://stdin", "r");
        $choice = trim(fgets($handle));
        fclose($handle);
        
        switch ($choice) {
            case '1':
                echo "\n✅ Skipping migration - table already exists\n";
                exit(0);
                
            case '2':
                echo "\n⚠️  DROPPING existing table...\n";
                $pdo->exec("DROP TABLE IF EXISTS service_level_calculations");
                echo "✓ Table dropped\n";
                break;
                
            case '3':
            default:
                echo "\n❌ Migration cancelled\n";
                exit(0);
        }
    }
    
    echo "\nExecuting migration...\n";
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );
    
    $pdo->beginTransaction();
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }
    }
    
    $pdo->commit();
    
    echo "✓ Migration executed successfully\n\n";
    
    // Verify table structure
    echo "Verifying table structure...\n";
    $columns = $pdo->query("SHOW COLUMNS FROM service_level_calculations")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nTable: service_level_calculations\n";
    echo str_repeat("-", 80) . "\n";
    echo sprintf("%-30s %-25s %-10s %-10s\n", "Field", "Type", "Null", "Key");
    echo str_repeat("-", 80) . "\n";
    
    foreach ($columns as $col) {
        echo sprintf(
            "%-30s %-25s %-10s %-10s\n",
            $col['Field'],
            $col['Type'],
            $col['Null'],
            $col['Key']
        );
    }
    
    echo str_repeat("-", 80) . "\n";
    echo "\n✅ Migration completed successfully!\n\n";
    
    // Show next steps
    echo "Next Steps:\n";
    echo "1. Access the calculator at: http://localhost/ponche-xtreme/hr/service_level_calculator.php\n";
    echo "2. Run tests: php tests/test_service_level_calculator.php\n";
    echo "3. Check documentation: SERVICE_LEVEL_CALCULATOR.md\n\n";
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo "\n❌ Database Error: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n\n";
    
    if (strpos($e->getMessage(), 'Cannot add foreign key constraint') !== false) {
        echo "Foreign Key Error Detected:\n";
        echo "- Make sure the 'users' table exists\n";
        echo "- Verify the 'users.id' column exists\n";
        echo "- Check InnoDB engine is being used\n\n";
    }
    
    exit(1);
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n\n";
    exit(1);
}
