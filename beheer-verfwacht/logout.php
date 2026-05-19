<?php
session_start();
session_unset();
session_destroy();
header('Location: index.php?uitgelogd=1');
exit;
