<?php
// Include the framework
require 'jogu-orm.php';

// Initialize the application
// (No database config is needed for a simple Hello World)
 $app = new Jogu([
    'debug' => true
]);

// Define a GET route for the root URL
 $app->get('/', function($params) {
    // Because this returns a string, the framework will just echo it
    return 'Hello, World! Welcome to Jogu!';
});

// Run the application
 $app->run();
?>