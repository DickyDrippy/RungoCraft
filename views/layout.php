<?php if (($page ?? '') === 'staff-login'): ?>
<?php
    if (!empty($flash)) {
        $staffFlash = $flash;
    }
    $pageFile = __DIR__ . '/pages/staff-login.php';
    require $pageFile;
?>
<?php else: ?>
<?php require __DIR__ . '/partials/header.php'; ?>
<main>
    <?php if (!empty($flash)): ?>
        <div class="flash" data-autohide><?= e($flash) ?></div>
    <?php endif; ?>
    <?php
    $pageFile = __DIR__ . '/pages/' . $page . '.php';
    require file_exists($pageFile) ? $pageFile : __DIR__ . '/pages/404.php';
    ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>

<?php endif; ?>
