<?php

/**
 * ساختِ سناریوی نمونه برای «درخواست‌ها و مسئول پیگیری» (R25).
 *
 * فقط برای محیطِ توسعه است: سه درخواست می‌سازد تا بتوانید هر سه نقش را در
 * مرورگر ببینید، بدونِ اینکه لازم باشد دستی چیزی وارد کنید.
 *
 *   php artisan tinker --execute="require 'scripts/demo-service-requests.php';"
 *
 * پاک‌کردنشان (همه با پیشوندِ [نمونه] ساخته می‌شوند، پس چیزی جز خودشان
 * حذف نمی‌شود):
 *
 *   php artisan tinker --execute="\App\Models\ServiceRequest::withoutGlobalScopes()->where('title','like','[نمونه]%')->forceDelete();"
 */

use App\Enums\ServiceRequestCategory;
use App\Enums\ServiceRequestPriority;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserRole;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequests\ServiceRequestService;

abort_unless(app()->environment('local'), 'فقط در محیط توسعه.');

$manager = User::withoutGlobalScopes()->where('role', UserRole::ComplexAdmin)->firstOrFail();
$complexId = $manager->complex_id;

/** ساکنِ درخواست‌دهنده و ساکنی که نقشِ «مسئول» را بازی می‌کند. */
$requester = User::withoutGlobalScopes()
    ->where('complex_id', $complexId)
    ->where('role', UserRole::Tenant)
    ->whereHas('units')
    ->firstOrFail();

$caretaker = User::withoutGlobalScopes()
    ->where('complex_id', $complexId)
    ->where('role', UserRole::Owner)
    ->whereHas('units')
    ->firstOrFail();

$service = app(ServiceRequestService::class);

$make = function (string $title, string $description, ServiceRequestCategory $category, ServiceRequestPriority $priority) use ($complexId, $requester) {
    return ServiceRequest::withoutGlobalScopes()->create([
        'complex_id' => $complexId,
        'unit_id' => $requester->units()->value('units.id'),
        'user_id' => $requester->id,
        'category' => $category,
        'priority' => $priority,
        'status' => ServiceRequestStatus::New,
        'title' => '[نمونه] '.$title,
        'description' => $description,
    ]);
};

// ۱) درخواستِ تازه، هنوز بی‌مسئول — مدیر باید واگذارش کند
$make(
    'لامپ راه‌پله طبقه ۳ سوخته',
    'دو شب است تاریک است و پله‌ها دیده نمی‌شود.',
    ServiceRequestCategory::Facilities,
    ServiceRequestPriority::Normal,
);

// ۲) واگذارشده به «مسئول» و در حال پیگیری
$assigned = $make(
    'آسانسور بین طبقه ۳ و ۴ گیر می‌کند',
    'از دیروز چند بار بین طبقه ۳ و ۴ ایستاده است.',
    ServiceRequestCategory::Elevator,
    ServiceRequestPriority::Urgent,
);
$service->assign($assigned, $manager, $caretaker);
$service->changeStatus($assigned->refresh(), $caretaker, ServiceRequestStatus::InProgress);
$service->comment($assigned->refresh(), $manager, 'با شرکت آسانسور تماس گرفتم، فردا می‌آیند.', internal: true);

// ۳) مسئول گفته انجام شد — منتظرِ تاییدِ ساکن
$resolved = $make(
    'شیر آب پارکینگ چکه می‌کند',
    'کف پارکینگ همیشه خیس است.',
    ServiceRequestCategory::Facilities,
    ServiceRequestPriority::Normal,
);
$service->assign($resolved, $manager, $caretaker);
$service->changeStatus($resolved->refresh(), $caretaker, ServiceRequestStatus::InProgress);
$service->changeStatus($resolved->refresh(), $caretaker, ServiceRequestStatus::Resolved, 'واشر شیر تعویض شد.');

echo PHP_EOL;
echo '✅ سه درخواستِ نمونه ساخته شد.'.PHP_EOL.PHP_EOL;
echo 'مدیر مجتمع     : '.$manager->phone.'  ('.$manager->name.')'.PHP_EOL;
echo 'ساکنِ درخواست‌دهنده: '.$requester->phone.'  ('.$requester->name.')'.PHP_EOL;
echo 'مسئولِ پیگیری   : '.$caretaker->phone.'  ('.$caretaker->name.')'.PHP_EOL;
echo PHP_EOL.'رمزِ همه در داده‌ی نمونه: password'.PHP_EOL;
echo 'حالا وارد /requests شوید و با هر سه شماره جداگانه نگاه کنید.'.PHP_EOL;
