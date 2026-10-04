@php($ar = $locale === 'ar')
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f4f6f6;font-family:Arial,Helvetica,sans-serif;color:#171819;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f6;padding:24px 0;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;max-width:560px;width:100%;">
        <tr><td style="background:#0c2427;padding:24px 32px;color:#ffffff;font-size:20px;font-weight:bold;">
          NAS LMS
        </td></tr>
        <tr><td style="padding:32px;">
          <h1 style="margin:0 0 12px;font-size:22px;color:#0c2427;">
            {{ $ar ? 'تمت دعوتك إلى عرض توضيحي' : 'You are invited to a demo' }}
          </h1>
          <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#454b52;">
            {{ $ar
              ? $contact->name.' من '.$contact->company_name.' أضافك ضيفًا إلى عرض توضيحي لمنصة NAS LMS.'
              : $contact->name.' from '.$contact->company_name.' added you as a guest to a NAS LMS product tour.' }}
          </p>
          <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#454b52;">
            {{ $ar
              ? 'سيتواصل فريقنا مع '.$contact->name.' لتحديد الموعد، وستصلك دعوة الاجتماع على هذا البريد.'
              : 'Our team will arrange the time with '.$contact->name.', and the meeting invitation will be sent to this email address.' }}
          </p>
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f2fbfa;border-radius:12px;margin:8px 0 4px;">
            <tr><td style="padding:16px 20px;">
              <p style="margin:0 0 8px;font-size:13px;color:#225f63;font-weight:bold;">
                {{ $ar ? 'جولة في منصة NAS LMS' : 'NAS LMS Product Tour' }}
              </p>
              <p style="margin:0;font-size:14px;line-height:1.7;color:#454b52;">
                <strong>{{ $ar ? 'المدة' : 'Duration' }}:</strong> {{ $ar ? '30 دقيقة' : '30 min' }}<br>
                <strong>{{ $ar ? 'الاجتماع' : 'Meeting' }}:</strong> Google Meet<br>
                <strong>{{ $ar ? 'الدعوة من' : 'Invited by' }}:</strong> {{ $contact->name }} ({{ $contact->email }})
              </p>
            </td></tr>
          </table>
          <p style="margin:20px 0 0;font-size:13px;line-height:1.6;color:#8a9096;">
            {{ $ar ? 'إذا لم تكن تتوقع هذه الرسالة فيمكنك تجاهلها.' : 'If you were not expecting this email, you can ignore it.' }}<br>
            {{ $ar ? 'مع تحيات فريق NAS LMS' : '— The NAS LMS Team' }}
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
