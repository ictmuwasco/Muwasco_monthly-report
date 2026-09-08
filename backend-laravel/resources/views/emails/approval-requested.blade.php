<!DOCTYPE html>
<html lang="en">
<body style="font-family: Arial, sans-serif; color: #1f2937; max-width: 560px; margin: 0 auto;">
    <h2 style="color: #0f4c81;">Monthly Report Review Requested</h2>

    <p>Hello {{ $managerRole }},</p>

    <p>
        The monthly performance report for
        <strong>{{ $periodName }}</strong>
        has been submitted and requires your review.
    </p>

    <p style="margin: 24px 0;">
        <a href="{{ $approveUrl }}"
           style="background:#16a34a;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;">
            Approve
        </a>
        &nbsp;
        <a href="{{ $rejectUrl }}"
           style="background:#dc2626;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;">
            Request Changes
        </a>
    </p>

    <p style="font-size: 13px; color: #6b7280;">
        This link is personal and expires on {{ $expiresAt }}.
        If you did not expect this request, please ignore this email
        or contact the system administrator.
    </p>
</body>
</html>
