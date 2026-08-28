<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MUWASCO Monitoring Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/css/legacy.css">
</head>
<body>
    <?php $legacySidebar = __DIR__ . '/components/sidebar-legacy.php';
          if (file_exists($legacySidebar)) include $legacySidebar; ?>

    <div class="main-container">
      <div class="main-content">
        <div class="page-content">
          <div class="reports-dashboard-container">
            <div class="reports-main-container">

              <div class="reports-header-section">
                <h1>ATHI WATER WORKS DEVELOPMENT AGENCY</h1>
                <h2>MONITORING TEMPLATE AND DATA CAPTURE FORMAT FOR MUWASCO</h2>
                <h3>(PRODUCTION, WATER QUALITY, SALES, REVENUE AND EXPENDITURE)</h3>
                <div class="mt-4">
                  <span class="badge bg-light text-dark fs-6">
                    <i class="bi bi-calendar-check me-2"></i>
                    <?php echo count($available_months); ?> Submitted Months Available
                  </span>
                </div>
              </div>

              <div class="reports-form-section no-print">
                <form method="POST" id="reportForm">
                  <div class="form-group">
                    <label class="reports-form-label">
                      <i class="bi bi-calendar-month me-2"></i>
                      Select Months for Report (Minimum 3 months required):
                    </label>
                    <div class="reports-months-grid" id="monthsGrid">
                      <?php foreach ($available_months as $month): ?>
                        <label class="report-month-checkbox" id="month-<?= $month['id'] ?>">
                          <input type="checkbox" name="months[]" value="<?= $month['id'] ?>"
                            <?= in_array($month['id'], $selected_months) ? 'checked' : '' ?>>
                          <i class="bi bi-calendar-check me-2"></i>
                          <?= date('F Y', strtotime($month['month_year'] . '-01')) ?>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>

                  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && count($selected_months) < 3): ?>
                    <div class="report-alert-message report-alert-error">
                      <i class="bi bi-exclamation-triangle-fill me-2"></i>
                      <strong>Selection Required:</strong> Please select at least 3 months to generate the report.
                    </div>
                  <?php endif; ?>

                  <div class="report-btn-group">
                    <button type="submit" name="generate_report" class="report-generate-btn" id="generateBtn">
                      <i class="bi bi-bar-chart me-2"></i>Generate Report Preview
                    </button>
                  </div>
                </form>
              </div>

              <?php if (!empty($preview_data) && count($selected_months) >= 3): ?>

                <div class="report-alert-message report-alert-success no-print">
                  <i class="bi bi-check-circle-fill me-2"></i>
                  <strong>Report Generated Successfully!</strong> Selected months:
                  <?php
                    $month_labels = [];
                    foreach ($selected_months as $id) {
                        foreach ($available_months as $m) {
                            if ($m['id'] == $id) {
                                $month_labels[] = date('F Y', strtotime($m['month_year'] . '-01'));
                                break;
                            }
                        }
                    }
                    echo implode(', ', $month_labels);
                  ?>
                </div>

                <div class="report-export-options no-print">
                  <h3 class="mb-4"><i class="bi bi-download me-2"></i>Export Options</h3>
                  <div class="report-export-buttons">
                    <a href="?export=word&months=<?= implode(',', $selected_months) ?>"
                       class="report-export-btn report-floating">
                      <i class="bi bi-file-word me-2"></i>Export to Word
                    </a>
                    <a href="?export=pdf&months=<?= implode(',', $selected_months) ?>"
                       class="report-export-btn report-export-btn-pdf report-floating" style="animation-delay:0.2s;">
                      <i class="bi bi-file-pdf me-2"></i>Export to PDF
                    </a>
                    <button type="button" onclick="window.print()" class="report-export-btn report-export-btn-print">
                      <i class="bi bi-printer me-2"></i>Print Report
                    </button>
                  </div>
                  <p class="text-muted mt-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Generated on: <?= date('F j, Y g:i A') ?>
                  </p>
                </div>

                <!-- ══ Report Preview ══════════════════════════════════════════ -->
                <div class="report-preview-section">
                  <div class="text-center mb-4">
                    <h2 class="report-title">
                      <i class="bi bi-droplet me-2"></i>WATER MONITORING REPORT
                    </h2>
                    <p class="text-muted report-subtitle">
                      WSP'S NAME: MUWASCO &nbsp;&nbsp; DATE OF MONITORING: <?= date('F Y') ?>
                    </p>
                  </div>

                  <!-- Single header row at the very top -->
                  <table class="report-preview-table">
                    <thead>
                      <tr>
                        <th class="column-code">NUMBER</th>
                        <th class="column-parameters">PARAMETERS</th>
                        <?php foreach ($selected_months as $month_id):
                            $month_label = '';
                            foreach ($available_months as $m) {
                                if ($m['id'] == $month_id) {
                                    $month_label = date('F Y', strtotime($m['month_year'] . '-01'));
                                    break;
                                }
                            }
                            echo '<th class="column-month">' . strtoupper($month_label) . '</th>';
                        endforeach; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php
                      $cat_count = 0;
                      foreach ($preview_data as $category => $parameters):
                          if (empty($parameters)) continue;
                          $cat_count++;

                          foreach ($parameters as $code => $row_data): ?>
                            <tr>
                              <td class="report-parameter-code-cell">
                                <span class="badge bg-primary"><?= htmlspecialchars($code) ?></span>
                              </td>
                              <td class="report-parameter-label-cell">
                                <div class="report-parameter-label">
                                  <?= htmlspecialchars($row_data['label']) ?>
                                </div>
                              </td>
                              <?php foreach ($selected_months as $month_id): ?>
                                <td class="report-parameter-value">
                                  <?= htmlspecialchars($row_data[$month_id] ?? '-') ?>
                                </td>
                              <?php endforeach; ?>
                            </tr>
                          <?php endforeach;

                          // Technical Manager signature after 5th category (Water Quality)
                          if ($cat_count == 5): ?>
                            <tr>
                              <td colspan="<?= 2 + count($selected_months) ?>" style="border:none;padding:20px 0;">
                                <div class="report-signature-section report-technical-signature">
                                  <h5><i class="bi bi-pen me-2"></i>Technical Manager Verification</h5>
                                  <p><strong>Above data verified by:</strong></p>
                                  <p class="h5"><strong>PETER KARENJU - TECHNICAL MANAGER</strong></p>
                                  <p class="mt-3">SIGN...............................................................DATE....................................................................</p>
                                </div>
                              </td>
                            </tr>
                          <?php endif;

                      endforeach; ?>
                    </tbody>
                  </table>

                  <!-- Final verification -->
                  <div class="report-signature-section report-final-signature">
                    <h5><i class="bi bi-shield-check me-2"></i>Final Verification &amp; Authorization</h5>
                    <div class="row mt-4">
                      <div class="col-md-6">
                        <p><strong>Data verified by:</strong></p>
                        <p class="h5"><strong>JOSEPH MAINA (CMT)</strong></p>
                        <p class="text-muted">COMMERCIAL MANAGER</p>
                        <p>Sign.......................................................................Date....................................................................</p>
                      </div>
                      <div class="col-md-6">
                        <p><strong>Authorized by:</strong></p>
                        <p class="h5"><strong>ENG. D. NG'ANG'A</strong></p>
                        <p class="text-muted">MANAGING DIRECTOR</p>
                        <p>Sign.......................................................................Date....................................................................</p>
                      </div>
                    </div>
                  </div>

                </div><!-- /report-preview-section -->

              <?php endif; ?>

            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const checkboxes  = document.querySelectorAll('input[name="months[]"]');
        const generateBtn = document.getElementById('generateBtn');

        function updateCheckboxStyles() {
          const selected = document.querySelectorAll('input[name="months[]"]:checked').length;
          checkboxes.forEach(cb => {
            cb.closest('.report-month-checkbox').classList.toggle('checked', cb.checked);
          });
          generateBtn.disabled = selected < 3;
          generateBtn.innerHTML = selected < 3
            ? '<i class="bi bi-bar-chart me-2"></i>Select ' + (3 - selected) + ' more month(s)'
            : '<i class="bi bi-bar-chart me-2"></i>Generate Report (' + selected + ' months)';
          generateBtn.classList.toggle('pulse', selected >= 3);
        }

        updateCheckboxStyles();
        checkboxes.forEach(cb => {
          cb.addEventListener('change', updateCheckboxStyles);
          cb.addEventListener('click', function () {
            const lbl = this.closest('.report-month-checkbox');
            lbl.style.transform = 'scale(0.95)';
            setTimeout(() => lbl.style.transform = '', 150);
          });
        });
      });
    </script>
</body>
</html>
