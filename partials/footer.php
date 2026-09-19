</div>
<script src="assets/js/app.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<?php if (($page ?? '') === 'pos'): ?><script src="assets/js/pos.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/pos.js') ?>"></script><?php endif; ?>
</body>
</html>
