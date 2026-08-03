<?php if ($alerts): ?>
    <?php foreach ($alerts as $alert): ?>
        <div class="alert alert-<?php echo h($alert['type']); ?> alert-dismissible fade show shadow-lift" role="alert">
            <?php echo h($alert['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-lift" role="alert">
        <?php echo h($successMessage); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
