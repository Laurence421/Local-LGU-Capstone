<?php
/**
 * Portfolio-demo previews of the other LGU2 systems (Ordinance, Minutes, Agenda, Committee,
 * Committee Journal, Archives). In the real deployment each is a separate application reached
 * through SSO; in DEMO_MODE these pages show what each system does with the demo's shared data.
 * tools/static-demo/demo-backend.js mirrors the same columns for the GitHub Pages demo.
 */

const LGU2_PREVIEWS = [
    'ordinance' => [
        'sso' => 'ordinance', 'roles' => ['ordinance'], 'title' => 'Ordinance and Resolution', 'icon' => 'fa-gavel',
        'about' => 'Tracks each ordinance and resolution through first, second and third reading to enactment.',
        'columns' => ['SP / Docket No.', 'Title', 'Type', 'Introducers', 'Status', 'Last Update'],
    ],
    'minutes' => [
        'sso' => 'minutes', 'roles' => ['minutes'], 'title' => 'Minutes Section', 'icon' => 'fa-handshake-angle',
        'about' => 'Records what each session decided on every measure taken up.',
        'columns' => ['Session Date', 'Measure', 'Type', 'Action Taken'],
    ],
    'agenda' => [
        'sso' => 'agenda', 'roles' => ['agenda', 'records'], 'title' => 'Agenda and Briefing', 'icon' => 'fa-calendar-days',
        'about' => 'Builds the order of business for the next session from measures waiting for council action.',
        'columns' => ['Item No.', 'Title', 'Type', 'For', 'Sponsor'],
    ],
    'committee' => [
        'sso' => 'committee', 'roles' => ['committee'], 'title' => 'Committee Management System', 'icon' => 'fa-users',
        'about' => 'Assigns docketed measures to committees and follows their hearings.',
        'columns' => ['Committee', 'Docket No.', 'Measure', 'Status', 'Received'],
    ],
    'committee-journal' => [
        'sso' => 'committeejournal', 'roles' => ['journal'], 'title' => 'Committee Journal', 'icon' => 'fa-check-to-slot',
        'about' => 'Keeps the journal of reviews and findings recorded on each measure.',
        'columns' => ['Date', 'Measure', 'Review Stage', 'Notes', 'Reviewed By'],
    ],
    'archives' => [
        'sso' => 'archives', 'roles' => ['archive'], 'title' => 'Archive Section', 'icon' => 'fa-box-archive',
        'about' => 'Holds the enacted ordinances and resolutions of the municipality.',
        'columns' => ['SP No.', 'Year', 'Title', 'Type', 'Category'],
    ],
];

const LGU2_READINGS = ['1st Reading', '2nd Reading', '3rd Reading'];

/** All rows of a query, or none when the table is missing (the page then shows its empty state). */
function lgu2_fetch_all($conn, $sql)
{
    $result = $conn->query($sql);
    if (!$result) {
        error_log('LGU2 preview query failed: ' . $conn->error);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/** Rows for one preview, each an array of cell texts in column order. */
function lgu2_preview_rows($key)
{
    $conn = require_db(db());
    $measures = lgu2_fetch_all($conn, "SELECT * FROM m9_similaritychecking");
    $byId = array_column($measures, null, 'm9_SC_ID');
    $latest = function ($m) { return $m['datetime_submitted'] ?: $m['date_created']; };
    $sortDesc = function (array &$rows, $index) {
        usort($rows, function ($a, $b) use ($index) { return strcmp((string) $b[$index], (string) $a[$index]); });
    };
    $rows = [];

    switch ($key) {
        case 'ordinance':
            foreach ($measures as $m) {
                if (in_array($m['measure_status'], LGU2_READINGS, true) || $m['measure_status'] === 'Enacted') {
                    $rows[] = [$m['sp_no'] ?: $m['docket_no'], $m['measure_title'], $m['measure_type'], $m['introducers'], $m['measure_status'], $latest($m)];
                }
            }
            $sortDesc($rows, 5);
            break;

        case 'minutes':
            $actions = ['1st Reading' => 'Approved on first reading', '2nd Reading' => 'Approved on second reading',
                        '3rd Reading' => 'Approved on third reading', 'Enacted' => 'Approved on third and final reading; enacted'];
            foreach ($measures as $m) {
                if (isset($actions[$m['measure_status']])) {
                    $rows[] = [substr($latest($m), 0, 10), $m['measure_title'], $m['measure_type'], $actions[$m['measure_status']]];
                }
            }
            $sortDesc($rows, 0);
            break;

        case 'agenda':
            $next = ['In Review for Docketing' => 'Referral to committee', '1st Reading' => 'Second reading',
                     '2nd Reading' => 'Third reading', '3rd Reading' => 'Final approval'];
            $items = array_filter($measures, function ($m) use ($next) { return isset($next[$m['measure_status']]); });
            usort($items, function ($a, $b) { return strcmp((string) ($a['datetime_submitted'] ?: $a['date_created']), (string) ($b['datetime_submitted'] ?: $b['date_created'])); });
            foreach (array_values($items) as $i => $m) {
                $rows[] = [$i + 1, $m['measure_title'], $m['measure_type'], $next[$m['measure_status']], explode(',', (string) $m['introducers'])[0]];
            }
            break;

        case 'committee':
            $records = env('RECORDS_DB_NAME', env('DB_NAME'));
            foreach (lgu2_fetch_all($conn, "SELECT MFL_Name, docket_no, measure_title, measure_status, datetime_submitted FROM `$records`.`m6_measuredocketing`") as $d) {
                $rows[] = [$d['MFL_Name'], $d['docket_no'], $d['measure_title'], ucfirst((string) $d['measure_status']), $d['datetime_submitted']];
            }
            $sortDesc($rows, 4);
            break;

        case 'committee-journal':
            $stages = ['1st_review' => '1st Review', '2nd_review' => '2nd Review', 'final_review' => 'Final Review'];
            foreach (lgu2_fetch_all($conn, "SELECT * FROM m9_measure_reviews") as $r) {
                $title = $byId[$r['m9_SC_ID']]['measure_title'] ?? ('Measure #' . $r['m9_SC_ID']);
                $rows[] = [$r['reviewed_at'], $title, $stages[$r['review_stage']] ?? $r['review_stage'], $r['review_notes'], $r['reviewed_by']];
            }
            $sortDesc($rows, 0);
            break;

        case 'archives':
            foreach ($measures as $m) {
                if ($m['measure_status'] === 'Enacted') {
                    $rows[] = [$m['sp_no'], $m['year_cc'], $m['measure_title'], $m['measure_type'], $m['category']];
                }
            }
            $sortDesc($rows, 1);
            break;
    }
    return $rows;
}

/** Render a whole preview page in the shared app shell. */
function lgu2_preview_page($key)
{
    $system = LGU2_PREVIEWS[$key];
    require_role($system['roles']); // same roles the sidebar shows this section to
    if (!DEMO_MODE) {
        // The real deployment opens the real system
        header('Location: ' . url('sso_go.php?system=' . $system['sso']));
        exit;
    }

    $search = trim((string) ($_GET['search'] ?? ''));
    $rows = lgu2_preview_rows($key);
    if ($search !== '') {
        $rows = array_values(array_filter($rows, function ($row) use ($search) {
            return stripos(implode(' ', $row), $search) !== false;
        }));
    }

    $pageTitle = $system['title'];
    include __DIR__ . '/layout/start.php';
    ?>
<div class="container mt-3">
<div class="card card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h1 class="mb-0"><i class="fas <?= e($system['icon']) ?>"></i> <?= e($system['title']) ?></h1>
    </div>
    <p class="text-muted mb-4">
        <?= e($system['about']) ?>
        Connected LGU2 system: in the full deployment it is a separate application; this preview shows its view of the demo's shared data.
    </p>

    <div class="row">
        <div class="col-12 mb-3">
            <form class="d-flex" method="GET" action="">
                <div class="input-group">
                    <input type="text" class="form-control" name="search" placeholder="Search records..." value="<?= e($search) ?>">
                    <button class="btn btn-primary" type="submit">Search</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <?php foreach ($system['columns'] as $column): ?>
                    <th><?= e($column) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody data-preview="<?= e($key) ?>">
                <?php if (!$rows): ?>
                <tr><td colspan="<?= count($system['columns']) ?>" class="text-center">No records found</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($row as $cell): ?>
                    <td><?= e($cell) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
<?php
    include __DIR__ . '/layout/end.php';
}
