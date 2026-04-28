<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';

$month_id = 35; // Use your month ID here (the one you are testing)
$TECHNICAL_MANAGER_CATEGORIES = [12, 13, 14, 15, 16];
$COMMERCIAL_MANAGER_CATEGORIES = [17, 18, 19, 20, 21, 22, 23, 24];

function areCategoriesFilledForManager($month_id, $category_ids) {
    global $conn;
    if (empty($category_ids)) return false;
    $filled_count = 0;
    foreach ($category_ids as $cat_id) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN (md.value IS NULL OR TRIM(md.value) = '' OR TRIM(md.value) = '-') THEN 1 ELSE 0 END) as empty_count
            FROM parameters p
            LEFT JOIN monthly_data md ON p.id = md.parameter_id AND md.month_id = ?
            WHERE p.category_id = ?
        ");
        $stmt->bind_param("ii", $month_id, $cat_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($res && $res['total'] > 0 && $res['empty_count'] == 0) {
            $filled_count++;
        }
    }
    return $filled_count === count($category_ids);
}

$tech_filled = areCategoriesFilledForManager($month_id, $TECHNICAL_MANAGER_CATEGORIES);
$comm_filled = areCategoriesFilledForManager($month_id, $COMMERCIAL_MANAGER_CATEGORIES);

echo "<h2>Approval Panel Test</h2>";
echo "<p>Month ID: $month_id</p>";

echo "<h3>Technical Categories (12-16):</h3>";
foreach ($TECHNICAL_MANAGER_CATEGORIES as $cat) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN (md.value IS NULL OR TRIM(md.value) = '' OR TRIM(md.value) = '-') THEN 1 ELSE 0 END) as empty_count
        FROM parameters p
        LEFT JOIN monthly_data md ON p.id = md.parameter_id AND md.month_id = ?
        WHERE p.category_id = ?
    ");
    $stmt->bind_param("ii", $month_id, $cat);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $filled = ($res['total'] > 0 && $res['empty_count'] == 0);
    echo "Category $cat: total=" . $res['total'] . ", empty=" . $res['empty_count'] . " → " . ($filled ? "FILLED ✓" : "NOT FILLED ✗") . "<br>";
}
echo "<p><strong>Overall Technical Filled: " . ($tech_filled ? "YES" : "NO") . "</strong></p>";

echo "<h3>Commercial Categories (17-24):</h3>";
foreach ($COMMERCIAL_MANAGER_CATEGORIES as $cat) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN (md.value IS NULL OR TRIM(md.value) = '' OR TRIM(md.value) = '-') THEN 1 ELSE 0 END) as empty_count
        FROM parameters p
        LEFT JOIN monthly_data md ON p.id = md.parameter_id AND md.month_id = ?
        WHERE p.category_id = ?
    ");
    $stmt->bind_param("ii", $month_id, $cat);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $filled = ($res['total'] > 0 && $res['empty_count'] == 0);
    echo "Category $cat: total=" . $res['total'] . ", empty=" . $res['empty_count'] . " → " . ($filled ? "FILLED ✓" : "NOT FILLED ✗") . "<br>";
}
echo "<p><strong>Overall Commercial Filled: " . ($comm_filled ? "YES" : "NO") . "</strong></p>";

// Also check if any parameters have missing data even if category is not fully filled
echo "<h3>Missing Data Details (empty fields):</h3>";
$missing_stmt = $conn->prepare("
    SELECT p.code, p.label, pc.name as category, md.value
    FROM parameters p
    JOIN parameter_categories pc ON p.category_id = pc.id
    LEFT JOIN monthly_data md ON p.id = md.parameter_id AND md.month_id = ?
    WHERE (md.value IS NULL OR TRIM(md.value) = '' OR TRIM(md.value) = '-')
      AND p.category_id BETWEEN 12 AND 24
    ORDER BY pc.id, p.code
");
$missing_stmt->bind_param("i", $month_id);
$missing_stmt->execute();
$missing_result = $missing_stmt->get_result();
if ($missing_result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'><tr><th>Category</th><th>Code</th><th>Label</th><th>Current Value</th></tr>";
    while ($row = $missing_result->fetch_assoc()) {
        echo "<tr><td>{$row['category']}</td><td>{$row['code']}</td><td>{$row['label']}</td><td>" . ($row['value'] === null ? 'NULL' : $row['value']) . "</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No missing data found for any category.</p>";
}
$missing_stmt->close();

$conn->close();
?>