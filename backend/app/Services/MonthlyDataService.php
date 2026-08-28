<?php

namespace App\Services;
/**
 * MonthlyDataService — business logic for the Monthly Data workflow.
 * Orchestrates MonthlyDataModel (persistence), MonthlyDataValidator (input)
 * and EmailService (notifications). Extracted from add_data.php handlers.
 */
class MonthlyDataService {
    /** Category assignment constants (moved from add_data.php). */
    const TECH_CATS = [12, 13, 14, 15, 16];
    const COMM_CATS = [17, 18, 19, 20, 21, 22, 23, 24];
    const ML_PARAMS = [221, 222, 172, 174, 305]; // multiline textarea IDs

    private $conn;
    private $model;
    private $mailer;

    public function __construct($conn, $model, $mailer) {
        $this->conn   = $conn;
        $this->model  = $model;
        $this->mailer = $mailer;
    }


    /** 7a. Manager: approve/reject via email token. Returns [success, error]. */
    public function handleTokenResponse(int $month_id, string $appr_role, string $appr_token, array $user_info, int $user_id, array $post): array {
        $va = $this->model->getValidApproval($month_id, $appr_role, $appr_token);
        if (!$va) return [null, null];

        $resp   = $post['response'] ?? '';
        $reason = trim($post['rejection_reason'] ?? '');
        if ($resp === 'approve') {
            $this->model->updateApprovalStatus((int)$va['id'], 'approved', null, $user_id);
            $sent = $this->mailer->adminDecision($month_id, $appr_role, $user_info, 'approve');
            return ["Your approval has been recorded successfully." . ($sent ? " The administrator has been notified." : " Note: Admin email notification could not be sent."), null];
        } elseif ($resp === 'reject') {
            $this->model->updateApprovalStatus((int)$va['id'], 'rejected', $reason, $user_id);
            $sent = $this->mailer->adminDecision($month_id, $appr_role, $user_info, 'reject', $reason);
            return [null, "Your feedback has been submitted." . ($sent ? " The administrator has been notified." : " Note: Admin email notification could not be sent.")];
        }
        return [null, null];
    }

    /** 7b. Manager: direct approve (logged-in, no token). Returns [success, error, email_note]. */
    public function directApprove(int $month_id, string $my_role, array $my_cats, array $user_info, int $user_id): array {
        if (!$this->model->catsFilled($month_id, $my_cats)) {
            return [null, "Approval cannot be submitted at this time — your assigned sections are not yet fully completed.", null];
        }
        $this->model->upsertDirectApproval($month_id, $my_role, $user_id);
        $sent    = $this->mailer->adminDecision($month_id, $my_role, $user_info, 'approve');
        $success = "Your approval has been recorded. All data in your sections has been confirmed as accurate.";
        $note    = $sent ? null : "The admin notification email could not be sent. Please verify your PHPMailer configuration.";
        return [$success, null, $note];
    }

    /** 7c. Admin: notify manager. Returns [success, error, email_note]. */
    public function notifyManager(int $month_id, string $mgr_role): array {
        $mgr = $this->model->getManagerByRole($mgr_role);
        if (!$mgr) {
            return [null, "No user account was found for the role '" . he($mgr_role) . "' with a registered email address. Please check the user settings.", null];
        }
        $token  = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+7 days'));
        $this->model->upsertApprovalToken($month_id, $mgr_role, $token, $expiry);

        $label    = $mgr_role === 'technical_manager' ? 'Technical' : 'Commercial';
        $mgr_name = trim("{$mgr['first_name']} {$mgr['last_name']}") ?: $mgr['username'];
        if ($this->mailer->reviewRequest($month_id, $mgr_role, $mgr, $token)) {
            return ["The review request has been sent to {$label} Manager " . he($mgr_name) . " ({$mgr['email']}) successfully.", null, null];
        }
        return [
            "The approval record has been saved, however the email to {$mgr['email']} could not be delivered.",
            null,
            "PHPMailer Setup Required: (1) Run <code>composer require phpmailer/phpmailer</code> in your project root. (2) Confirm your Gmail App Password is correct — not your regular Google password. (3) Enable 2-Step Verification in Gmail, then create an App Password under Security → App Passwords. (4) In XAMPP php.ini, ensure <code>extension=openssl</code> is uncommented, then restart Apache. (5) For persistent SSL errors, uncomment the SMTPOptions line in <code>EmailService::send()</code>.",
        ];
    }

    /** 7d. Admin: save section (validated, permission-checked, transactional). Returns [success, error]. */
    public function saveSection(int $month_id, array $data, int $role_id, bool $is_admin): array {
        $validation = MonthlyDataValidator::validateCodes($this->conn, $data);
        if (!$validation['ok']) {
            $error = 'Some values could not be saved — please correct the highlighted fields: '
                   . implode(' ', array_slice(array_values($validation['errors']), 0, 4))
                   . (count($validation['errors']) > 4 ? ' (+' . (count($validation['errors']) - 4) . ' more)' : '');
            return [null, $error];
        }
        foreach ($data as $c => $v) { if (trim($v) === '') $data[$c] = '-'; }

        $this->conn->begin_transaction();
        try {
            foreach ($data as $c => $v) {
                $v  = trim($v);
                $pm = $this->model->getParameterIdByCode($c);
                if ($pm) {
                    if (!hasParameterAccess((int)$pm['id'], $role_id, $is_admin))
                        throw new Exception("Permission denied for parameter: $c");
                    $this->model->upsertValue($month_id, (int)$pm['id'], $v);
                }
            }
            $this->conn->commit();
            return ["Section data saved successfully.", null];
        } catch (Exception $e) {
            $this->conn->rollback();
            return [null, "An error occurred while saving: " . $e->getMessage()];
        }
    }

    /** 7e. Admin: final submit. Returns [success, error, is_submitted]. */
    public function submitFinal(int $month_id, int $role_id, bool $is_admin): array {
        $all_cats = $this->model->getCategoriesWithParams($role_id, $is_admin);
        foreach ($all_cats as $cd) {
            if ($this->model->validateSection($month_id, (int)$cd['category']['id']) !== true) {
                return [null, "The report cannot be submitted until all sections are completely and correctly filled.", false];
            }
        }
        $this->model->normalizeEmptyValues($month_id);
        if ($this->model->submitMonth($month_id)) {
            return ["The monthly report has been successfully submitted and is now locked for editing.", null, true];
        }
        return [null, "A database error occurred during submission. Please try again.", false];
    }


    /** Page-data assembly for the entry view. */
    public function buildPageData(int $month_id, int $role_id, bool $is_admin): array {
        $cats_params = $this->model->getCategoriesWithParams($role_id, $is_admin);
        $total_cats  = count($cats_params);
        $saved_cats  = $complete_cats = 0;
        foreach ($cats_params as $cd) {
            if ($this->model->isSaved($month_id, (int)$cd['category']['id'], $role_id, $is_admin)) {
                $saved_cats++;
                if ($this->model->validateSection($month_id, (int)$cd['category']['id']) === true) $complete_cats++;
            }
        }
        return [
            'existing_data' => $this->model->getExistingData($month_id),
            'cats_params'   => $cats_params,
            'total_cats'    => $total_cats,
            'saved_cats'    => $saved_cats,
            'complete_cats' => $complete_cats,
            'tech_appr'     => $this->model->getApproval($month_id, 'technical_manager'),
            'comm_appr'     => $this->model->getApproval($month_id, 'commercial_manager'),
            'tech_filled'   => $this->model->catsFilled($month_id, self::TECH_CATS),
            'comm_filled'   => $this->model->catsFilled($month_id, self::COMM_CATS),
        ];
    }
}

