<?= e($old('email', 'empty')) ?>|<?= e($error('email') ?? 'none') ?>|<?= is_string($old) ? $old : 'helper' ?>|
