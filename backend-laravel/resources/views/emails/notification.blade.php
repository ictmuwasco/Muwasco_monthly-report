<!DOCTYPE html>
<html lang="en">
<body style="font-family: Arial, sans-serif; color: #1f2937; max-width: 560px; margin: 0 auto;">
    <h2 style="color: #0f4c81;">{{ $title }}</h2>

    @foreach ($introLines as $line)
        <p style="margin: 6px 0;">{{ $line }}</p>
    @endforeach

    @if ($ctaUrl)
        <p style="margin: 24px 0;">
            <a href="{{ $ctaUrl }}"
               style="background:#0f4c81;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;">
                {{ $ctaText ?? 'View' }}
            </a>
        </p>
    @endif

    <p style="font-size: 13px; color: #6b7280;">
        This is an automated message from the MUWASCO Monthly Performance Reporting System.
    </p>
</body>
</html>
