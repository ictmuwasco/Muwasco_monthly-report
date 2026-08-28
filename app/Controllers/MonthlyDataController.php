<?php
/**
 * MonthlyDataController — HTTP dispatch layer for the Monthly Data feature.
 * Receives the authenticated request context, routes POST actions to
 * MonthlyDataService, and prepares variables for the entry view.
 */
class MonthlyDataController {
    private $service;
    private $model;

    public function __construct($service, $model) {
        $this->service = $service;
        $this->model   = $model;
    }

    /**
     * Handle the request and return view variables.
     * $ctx: [month, month_id, user_info, user_id, role_id, is_admin,
     *        is_tech_mgr, is_comm_mgr, is_POST, appr_token, appr_role]
     */
    public function handle(array $ctx): array {
        $success = $error = $email_note = null;
        $month   = $ctx['month'];
        $month_id = $ctx['month_id'];
        $is_submitted = $month['status'] === 'submitted';

        if ($ctx['is_POST']) {
            $action = $_POST['action'] ?? '';

            // 7a. Manager: approve/reject via email token
            if ($ctx['appr_token'] !== '' && $ctx['appr_role'] !== '' && ($ctx['is_tech_mgr'] || $ctx['is_comm_mgr'])) {
                [$success, $error] = $this->service->handleTokenResponse(
                    $month_id, $ctx['appr_role'], $ctx['appr_token'],
                    $ctx['user_info'], $ctx['user_id'], $_POST
                );
            }
            // 7b. Manager: direct approve
            elseif ($action === 'manager_direct_approve' && ($ctx['is_tech_mgr'] || $ctx['is_comm_mgr'])) {
                $my_role = $ctx['is_tech_mgr'] ? 'technical_manager' : 'commercial_manager';
                $my_cats = $my_role === 'technical_manager' ? MonthlyDataService::TECH_CATS : MonthlyDataService::COMM_CATS;
                [$success, $error, $email_note] = $this->service->directApprove(
                    $month_id, $my_role, $my_cats, $ctx['user_info'], $ctx['user_id']
                );
            }
            // 7c. Admin: notify manager
            elseif ($action === 'notify_manager' && $ctx['is_admin']) {
                [$success, $error, $email_note] = $this->service->notifyManager(
                    $month_id, $_POST['manager_role'] ?? ''
                );
            }
            // 7d. Admin: save section
            elseif ($action === 'save_section' && $ctx['is_admin']) {
                [$success, $error] = $this->service->saveSection(
                    $month_id, $_POST['data'] ?? [], $ctx['role_id'], true
                );
            }
            // 7e. Admin: final submit
            elseif ($action === 'submit_final' && $ctx['is_admin']) {
                [$success, $error, $submitted] = $this->service->submitFinal(
                    $month_id, $ctx['role_id'], true
                );
                if ($submitted) {
                    $is_submitted   = true;
                    $month['status'] = 'submitted';
                }
            }
        }

        // 8. Page data
        $page = $this->service->buildPageData($month_id, $ctx['role_id'], $ctx['is_admin']);
        $full_name = trim("{$ctx['user_info']['first_name']} {$ctx['user_info']['last_name']}") ?: $ctx['user_info']['username'];
        $pct  = $page['total_cats'] > 0 ? round($page['saved_cats'] / $page['total_cats'] * 100) : 0;
        $cpct = $page['total_cats'] > 0 ? round($page['complete_cats'] / $page['total_cats'] * 100, 1) : 0;

        return array_merge($page, [
            'month'        => $month,
            'is_submitted' => $is_submitted,
            'user_info'    => $ctx['user_info'],
            'full_name'    => $full_name,
            'is_admin'     => $ctx['is_admin'],
            'is_tech_mgr'  => $ctx['is_tech_mgr'],
            'is_comm_mgr'  => $ctx['is_comm_mgr'],
            'role_id'      => $ctx['role_id'],
            'user_id'      => $ctx['user_id'],
            'appr_token'   => $ctx['appr_token'],
            'appr_role'    => $ctx['appr_role'],
            'success'      => $success,
            'error'        => $error,
            'email_note'   => $email_note,
            'pct'          => $pct,
            'cpct'         => $cpct,
        ]);
    }
}
