<?php
/** @var string $__title */
/** @var string $__head */
/** @var string $__bodyClass */
/** @var string $__content */

$__title = isset($__title) ? (string)$__title : 'PhotoClassifier';
$__head = isset($__head) ? (string)$__head : '';
$__bodyClass = isset($__bodyClass) ? (string)$__bodyClass : '';
$__content = isset($__content) ? (string)$__content : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($__title, ENT_QUOTES); ?></title>
    <link rel="icon" type="image/png" href="img/favicon.png">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/app.css">

    <?php echo $__head; ?>
</head>
<body class="<?php echo htmlspecialchars($__bodyClass, ENT_QUOTES); ?>">
<div class="app">
<?php echo $__content; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
