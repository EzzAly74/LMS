@php(['ar' => $ar, 'dir' => $dir, 'align' => $align, 'font' => $font] = \App\Support\Mail\Bidi::forLocale($locale))
@php($iso = fn (?string $v) => \App\Support\Mail\Bidi::isolate($v))
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;background:#f4f6f6;font-family:{{ $font }};color:#171819;">
  <table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background:#f4f6f6;padding:24px 0;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background:#ffffff;border-radius:16px;overflow:hidden;max-width:560px;width:100%;">
        <tr><td dir="ltr" style="background:#0c2427;padding:24px 32px;color:#ffffff;font-size:20px;font-weight:bold;text-align:{{ $align }};">
          NAS LMS
        </td></tr>
        <tr><td dir="{{ $dir }}" style="padding:32px;text-align:{{ $align }};">
          <h1 style="margin:0 0 12px;font-size:22px;color:#0c2427;text-align:{{ $align }};">
            {{ $ar ? 'تمت دعوتك إلى عرض توضيحي' : 'You are invited to a demo' }}
          </h1>
          <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#454b52;text-align:{{ $align }};">
            @if($ar)
              {{ $iso($contact->name) }} من {{ $iso($contact->company_name) }} أضافك ضيفًا إلى عرض توضيحي لمنصة {{ $iso('NAS LMS') }}.
            @else
              {{ $iso($contact->name) }} from {{ $iso($contact->company_name) }} added you as a guest to a NAS LMS product tour.
            @endif
          </p>
          <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#454b52;text-align:{{ $align }};">
            @if($ar)
              سيتواصل فريقنا مع {{ $iso($contact->name) }} لتحديد الموعد، وستصلك دعوة الاجتماع على هذا البريد.
            @else
              Our team will arrange the time with {{ $iso($contact->name) }}, and the meeting invitation will be sent to this email address.
            @endif
          </p>
          <table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background:#f2fbfa;border-radius:12px;margin:8px 0 4px;">
            <tr><td dir="{{ $dir }}" style="padding:16px 20px;text-align:{{ $align }};">
              <p style="margin:0 0 8px;font-size:13px;color:#225f63;font-weight:bold;text-align:{{ $align }};">
                @if($ar) جولة في منصة {{ $iso('NAS LMS') }} @else NAS LMS Product Tour @endif
              </p>
              <p style="margin:0;font-size:14px;line-height:1.7;color:#454b52;text-align:{{ $align }};">
                <strong>{{ $ar ? 'المدة' : 'Duration' }}:</strong> {{ $ar ? '30 دقيقة' : '30 min' }}<br>
                <strong>{{ $ar ? 'الاجتماع' : 'Meeting' }}:</strong> {{ $iso('Google Meet') }}<br>
                <strong>{{ $ar ? 'الدعوة من' : 'Invited by' }}:</strong> {{ $iso($contact->name) }} ({{ $iso($contact->email) }})
              </p>
            </td></tr>
          </table>
          <p style="margin:20px 0 0;font-size:13px;line-height:1.6;color:#8a9096;text-align:{{ $align }};">
            {{ $ar ? 'إذا لم تكن تتوقع هذه الرسالة فيمكنك تجاهلها.' : 'If you were not expecting this email, you can ignore it.' }}<br>
            @if($ar) مع تحيات فريق {{ $iso('NAS LMS') }} @else — The NAS LMS Team @endif
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
