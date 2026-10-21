<?= e($old('email', 'empty')) ?>|<?= e($error('email') ?? 'none') ?>|<?= isset($old) && is_string($old) ? $old : 'helper' ?>|<?= e($csrf) ?>
