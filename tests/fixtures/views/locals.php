<form method="post"><input type="hidden" name="_token" value="<?= e($csrf) ?>">
email=<?= e($old('email')) ?>|qty=<?= e($old('lines.1.qty', 'none')) ?>|err=<?= e($error('email') ?? '-') ?>|count=<?= count($errors) ?>|<?= $this->partial('locals_partial') ?></form>
