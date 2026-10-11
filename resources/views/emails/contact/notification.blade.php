@php(['ar' => $ar, 'dir' => $dir, 'align' => $align, 'font' => $font] = \App\Support\Mail\Bidi::forLocale($locale))
@php($iso = fn (?string $v) => \App\Support\Mail\Bidi::isolate($v))
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;background:#f4f6f6;font-family:{{ $font }};color:#171819;">
  <table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background:#f4f6f6;padding:24px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background:#ffffff;border-radius:16px;overflow:hidden;max-width:600px;width:100%;">
        <tr><td dir="{{ $dir }}" style="background:#0c2427;padding:20px 32px;color:#ffffff;font-size:18px;font-weight:bold;text-align:{{ $align }};">
          {{ $ar ? 'طلب عرض توضيحي جديد' : 'New demo request' }}
        </td></tr>
        <tr><td dir="{{ $dir }}" style="padding:28px 32px;text-align:{{ $align }};">
          <p style="margin:0 0 20px;font-size:14px;color:#454b52;text-align:{{ $align }};">
            {{ $ar
              ? 'تم استلام طلب حجز عرض توضيحي جديد عبر الموقع. التفاصيل:'
              : 'A new demo request was submitted through the website. Details below:' }}
          </p>
          <table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="border-collapse:collapse;font-size:14px;">
            @php($rows = [
              ($ar ? 'الاسم' : 'Name') => $contact->name,
              ($ar ? 'البريد الإلكتروني' : 'Email') => $contact->email,
              ($ar ? 'الهاتف' : 'Phone') => $contact->phone ?: '—',
              ($ar ? 'المسمى الوظيفي' : 'Job title') => $contact->job_title ?: '—',
              ($ar ? 'الشركة' : 'Company') => $contact->company_name ?: '—',
              ($ar ? 'ضيوف' : 'Guests') => ($contact->guests ? implode(', ', $contact->guests) : '—'),
              ($ar ? 'أُرسل في' : 'Submitted at') => $contact->created_at?->format('Y-m-d H:i'),
            ])
            @foreach($rows as $label => $value)
              <tr>
                <td dir="{{ $dir }}" style="padding:10px 12px;border-bottom:1px solid #eef0f0;color:#8a9096;width:38%;font-weight:bold;vertical-align:top;text-align:{{ $align }};">{{ $label }}</td>
                <td dir="{{ $dir }}" style="padding:10px 12px;border-bottom:1px solid #eef0f0;color:#171819;text-align:{{ $align }};">{{ $iso($value) }}</td>
              </tr>
            @endforeach
          </table>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
