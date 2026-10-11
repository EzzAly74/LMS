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
            {{ $ar ? 'شكرًا لتواصلك معنا' : 'Thanks for reaching out' }}
          </h1>
          <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#454b52;text-align:{{ $align }};">
            {{ $ar ? 'مرحبًا' : 'Hi' }} {{ $iso($contact->name) }}{{ $ar ? '،' : ',' }}
          </p>
          <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#454b52;text-align:{{ $align }};">
            @if($ar)
              لقد استلمنا طلبك لحجز عرض توضيحي لمنصة {{ $iso('NAS LMS') }}. سيتواصل معك فريقنا قريبًا لتحديد الموعد المناسب.
            @else
              We have received your request to book a NAS LMS demo. Our team will contact you shortly to arrange a convenient time.
            @endif
          </p>
          <table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background:#f2fbfa;border-radius:12px;margin:8px 0 4px;">
            <tr><td dir="{{ $dir }}" style="padding:16px 20px;text-align:{{ $align }};">
              <p style="margin:0 0 8px;font-size:13px;color:#225f63;font-weight:bold;{{ $ar ? '' : 'text-transform:uppercase;' }}text-align:{{ $align }};">
                {{ $ar ? 'ملخص طلبك' : 'Your request' }}
              </p>
              <p style="margin:0;font-size:14px;line-height:1.7;color:#454b52;text-align:{{ $align }};">
                <strong>{{ $ar ? 'الاسم' : 'Name' }}:</strong> {{ $iso($contact->name) }}<br>
                <strong>{{ $ar ? 'البريد الإلكتروني' : 'Email' }}:</strong> {{ $iso($contact->email) }}<br>
                @if($contact->phone)<strong>{{ $ar ? 'الهاتف' : 'Phone' }}:</strong> {{ $iso($contact->phone) }}<br>@endif
                @if($contact->job_title)<strong>{{ $ar ? 'المسمى الوظيفي' : 'Job title' }}:</strong> {{ $iso($contact->job_title) }}<br>@endif
                @if($contact->company_name)<strong>{{ $ar ? 'الشركة' : 'Company' }}:</strong> {{ $iso($contact->company_name) }}@endif
              </p>
            </td></tr>
          </table>
          <p style="margin:20px 0 0;font-size:13px;line-height:1.6;color:#8a9096;text-align:{{ $align }};">
            @if($ar)
              مع تحيات فريق {{ $iso('NAS LMS') }}
            @else
              — The NAS LMS Team
            @endif
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
