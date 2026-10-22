<?php
/**
 * Model Autoloader
 * Extol Global Service
 * Automatically loads model classes
 */

function loadModel($model_name) {
    $model_path = __DIR__ . '/models/' . $model_name . '.php';

    if (file_exists($model_path)) {
        require_once $model_path;
        return true;
    }

    return false;
}

// Load all models
$models = ['Customer', 'Transaction', 'Staff', 'Loan', 'LoanApplication', 'KYC', 'Receipt', 'ContactMessage'];

foreach ($models as $model) {
    if (!loadModel($model)) {
        // Models will be loaded on demand if needed
    }
}

?>
