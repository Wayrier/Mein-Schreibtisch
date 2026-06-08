<?php

// =======================================
// users.php
// Zweck: Alle Benutzer anzeigen (Admin only)
// =======================================

require_once '../src/admin_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';
require_once '../src/access_requests.php';
require_once '../src/response.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];
$users = [];
$access_requests = [];

try {
    $stmt = $pdo->query("SELECT id, username, email, role, created_at FROM users");
    $users = $stmt->fetchAll();
    $access_requests = fetch_open_access_requests($pdo);
} catch (PDOException $e) {
    error_log("Benutzer konnten nicht geladen werden: " . $e->getMessage());
    app_fail('Fehler beim Laden der Benutzer. Bitte versuchen Sie es spaeter erneut.', 500);
}

app_render_header('Usermanagement', 'users', [
    'subtitle' => 'Benutzerkonten verwalten und Rollen pruefen.',
    'actions' => '<a class="button" href="create_user.php">+ Neuer Benutzer</a>'
]);
?>

<section class="panel">
    <div class="card-header">
        <h2 class="card-title">Zugriffsanfragen</h2>
    </div>

    <?php if (empty($access_requests)): ?>
        <p class="empty-state">Keine offenen Konto- oder Passwort-Anfragen.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>Art</th>
                <th>Name</th>
                <th>Benutzer/E-Mail</th>
                <th>Nachricht</th>
                <th>Datum</th>
                <th>Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($access_requests as $request): ?>
                <tr>
                    <td><?= $request['request_type'] === 'account' ? 'Konto' : 'Passwort' ?></td>
                    <td><?= e($request['full_name'] ?: '-') ?></td>
                    <td>
                        <?php if (!empty($request['username'])): ?>
                            <?= e($request['username']) ?><br>
                        <?php endif; ?>
                        <?= e($request['email'] ?: '-') ?>
                    </td>
                    <td><?= nl2br(e($request['message'] ?: '-')) ?></td>
                    <td><?= e((string)$request['created_at']) ?></td>
                    <td class="action-cell">
                        <div class="action-group">
                            <?php if ($request['request_type'] === 'account'): ?>
                                <a class="button-link" href="create_user.php">&#43; Benutzer erstellen</a>
                            <?php else: ?>
                                <a class="button-link" href="#users-table">&#9776; Benutzerliste</a>
                            <?php endif; ?>
                            <form method="POST" action="process_access_request.php" class="action-inline">
                                <input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                <button type="submit" class="button-link">&#10003; Erledigt</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="card-header">
        <h2 class="card-title">Benutzer</h2>
    </div>

    <table id="users-table" class="users-table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Benutzername</th>
            <th>Email</th>
            <th>Rolle</th>
            <th>Erstellt am</th>
            <th>Aktionen</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <?php
            $role_label = $user['role'] === 'admin' ? 'Admin' : 'Standard';
            $role_class = $user['role'] === 'admin' ? 'role-chip-admin' : 'role-chip-standard';
            ?>
            <tr>
                <td><?= (int)$user['id'] ?></td>
                <td><?= e($user['username']) ?></td>
                <td><?= e($user['email']) ?></td>
                <td><span class="role-chip <?= e($role_class) ?>"><?= e($role_label) ?></span></td>
                <td><?= e((string)$user['created_at']) ?></td>
                <td class="action-cell user-actions-cell">
                    <div class="action-group user-actions">
                        <a class="button-link user-action-button" href="edit_user.php?id=<?= (int)$user['id'] ?>">&#9998; Bearbeiten</a>
                        <form method="POST" action="delete_user.php" class="action-inline ajax-delete-form user-delete-form" data-ajax-url="delete_user_ajax.php">
                            <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                            <button type="submit" class="button-link button-link-danger user-action-button user-delete-button">&#128465; Loeschen</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<script>
document.querySelectorAll('.ajax-delete-form').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!confirm('Wirklich loeschen?')) {
            return;
        }

        try {
            const response = await fetch(form.dataset.ajaxUrl, {
                method: 'POST',
                body: new FormData(form)
            });
            const data = await response.json();

            if (data.success) {
                form.closest('tr').remove();
            } else {
                alert('Fehler beim Loeschen');
            }
        } catch (error) {
            alert('Fehler beim Loeschen');
        }
    });
});
</script>

<?php app_render_footer(); ?>
