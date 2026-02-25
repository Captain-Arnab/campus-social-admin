<?php
session_start();
session_unset();
session_destroy();

// Redirect to login page with a logout flag
header("Location: index.php?msg=logout");
exit();
?>