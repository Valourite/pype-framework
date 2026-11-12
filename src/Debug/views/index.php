<?php
/**
 * /_pype: the development screens this app has.
 *
 * @var array<string, string> $links
 * @var int                   $count  routes in the app
 */
$title = 'Development';
$current = 'pype';
require __DIR__ . '/_head.php';
$about = [
    'Routes' => "Every route as the pipeline it is: its stages in the order they run, what each needs from the context and what it puts there. {$count} " . ($count === 1 ? 'route' : 'routes') . '.',
    'Requests' => 'What each request did: every stage it went through, how long each took, what it put in the context and the queries it ran.',
    'Sandbox' => 'Send a request into a throw-away database built from your migrations and seeder: the response, what each stage did, and which rows changed.',
    'Logs' => 'The app\'s log, live, filtered by level, text or request.',
    'Mail' => 'Every email the app sent while developing, with its links.',
];
?>
</header>
<main>
    <h1>Development screens</h1>
    <p class="lead">Only here in debug, or behind the stages you gave them. Never in production.</p>
<?php foreach ($about as $label => $what) { ?>
<?php if (isset($links[$label])) { ?>
    <p class="panel" style="padding: 12px 14px; margin: 0 0 8px;"><a href="<?= $h($links[$label]) ?>"><strong><?= $h($label) ?></strong></a><br><span class="muted"><?= $h($what) ?></span></p>
<?php } ?>
<?php } ?>
</main>
</body>
</html>
