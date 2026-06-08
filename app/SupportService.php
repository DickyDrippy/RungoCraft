<?php
declare(strict_types=1);

final class SupportService
{
    public function handle(string $action, array $data, ?array $user): array
    {
        $publicActions = ['support_chat_create'];
        if (!$user && !in_array($action, $publicActions, true)) {
            return ['ok' => false, 'message' => 'Для цієї заявки потрібно увійти або зареєструватися в кабінеті.'];
        }

        return match ($action) {
            'support_ticket_create' => $this->createTicket($data, $user),
            'support_callback_create' => $this->createCallback($data, $user),
            'support_product_question_create' => $this->createProductQuestion($data, $user),
            'support_notify_create' => $this->createAvailabilityRequest($data, $user),
            'support_calculation_create' => $this->createCalculationRequest($data, $user),
            'support_chat_create' => $this->createChatMessage($data, $user),
            'support_admin_update' => $this->updateRequestStatus($data, $user),
            'support_admin_delete' => $this->deleteRequest($data, $user),
            'support_admin_bulk_delete' => $this->bulkDeleteRequests($data, $user),
            default => ['ok' => false, 'message' => 'Невідома дія підтримки.'],
        };
    }

    public function adminData(?array $user): array
    {
        if (!$this->canManage($user)) {
            return [
                'stats' => [],
                'callbacks' => [],
                'calculations' => [],
                'tickets' => [],
                'product_questions' => [],
                'availability_requests' => [],
            ];
        }

        return [
            'stats' => [
                'callbacks' => $this->countTable('RC_CALLBACK_REQUESTS'),
                'calculations' => $this->countTable('RC_CALCULATION_REQUESTS'),
                'tickets' => $this->countTable('RC_SUPPORT_TICKETS'),
                'product_questions' => $this->countTable('RC_PRODUCT_QUESTIONS'),
                'availability_requests' => $this->countTable('RC_AVAILABILITY_REQUESTS'),
            ],
            'callbacks' => $this->latest('rc_callback_requests'),
            'calculations' => $this->latest('rc_calculation_requests'),
            'tickets' => $this->latest('rc_support_tickets'),
            'product_questions' => $this->latest('rc_product_questions'),
            'availability_requests' => $this->latest('rc_availability_requests'),
        ];
    }

    private function createTicket(array $data, ?array $user): array
    {
        $name = $this->value($data, 'name', $user['name'] ?? '');
        $phone = $this->value($data, 'phone', $user['phone'] ?? '');
        $email = $this->value($data, 'email', $user['email'] ?? '');
        $subject = $this->value($data, 'subject', 'Звернення з сайту');
        $message = $this->value($data, 'message');

        if ($name === '' || ($phone === '' && $email === '') || $message === '') {
            return ['ok' => false, 'message' => 'Заповніть імʼя, контакт і текст звернення.'];
        }

        $ok = Database::execute(
            "INSERT INTO rc_support_tickets (user_id, name, phone, email, subject, message, status, source)
             VALUES (:user_id, :name, :phone, :email, :subject, :message, 'new', 'site')",
            [
                'user_id' => $this->userId($user),
                'name' => $name,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'subject' => $subject,
                'message' => $message,
            ]
        );

        return $this->result($ok, 'Звернення прийнято. Менеджер відповість найближчим часом.');
    }

    private function createCallback(array $data, ?array $user): array
    {
        $name = $this->value($data, 'name', $user['name'] ?? '');
        $phone = $this->value($data, 'phone', $user['phone'] ?? '');
        $preferredTime = $this->value($data, 'preferred_time');
        $comment = $this->value($data, 'comment');

        if ($name === '' || $phone === '') {
            return ['ok' => false, 'message' => 'Вкажіть імʼя та телефон для дзвінка.'];
        }

        $ok = Database::execute(
            "INSERT INTO rc_callback_requests (user_id, name, phone, preferred_time, comment_text, status, source)
             VALUES (:user_id, :name, :phone, :preferred_time, :comment_text, 'new', 'site')",
            [
                'user_id' => $this->userId($user),
                'name' => $name,
                'phone' => $phone,
                'preferred_time' => $preferredTime !== '' ? $preferredTime : null,
                'comment_text' => $comment !== '' ? $comment : null,
            ]
        );

        if ($ok) {
            $requestId = $this->latestRequestId('rc_callback_requests', $name, $phone);
            (new NotificationService())->notifySupportRequest('callback', $requestId);
        }

        return $this->result($ok, 'Заявку на дзвінок прийнято.');
    }

    private function createProductQuestion(array $data, ?array $user): array
    {
        $productId = (int)($data['product_id'] ?? 0);
        $name = $this->value($data, 'name', $user['name'] ?? '');
        $phone = $this->value($data, 'phone', $user['phone'] ?? '');
        $email = $this->value($data, 'email', $user['email'] ?? '');
        $message = $this->value($data, 'message');

        if ($productId <= 0 || $message === '' || ($phone === '' && $email === '')) {
            return ['ok' => false, 'message' => 'Вкажіть товар, контакт і питання.'];
        }

        $ok = Database::execute(
            "INSERT INTO rc_product_questions (user_id, product_id, name, phone, email, message, status)
             VALUES (:user_id, :product_id, :name, :phone, :email, :message, 'new')",
            [
                'user_id' => $this->userId($user),
                'product_id' => $productId,
                'name' => $name !== '' ? $name : 'Клієнт',
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'message' => $message,
            ]
        );

        return $this->result($ok, 'Питання по товару надіслано менеджеру.');
    }

    private function createAvailabilityRequest(array $data, ?array $user): array
    {
        $productId = (int)($data['product_id'] ?? 0);
        $name = $this->value($data, 'name', $user['name'] ?? '');
        $phone = $this->value($data, 'phone', $user['phone'] ?? '');
        $email = $this->value($data, 'email', $user['email'] ?? '');

        if ($productId <= 0 || ($phone === '' && $email === '')) {
            return ['ok' => false, 'message' => 'Вкажіть контакт для повідомлення про появу.'];
        }

        $ok = Database::execute(
            "INSERT INTO rc_availability_requests (user_id, product_id, name, phone, email, status)
             VALUES (:user_id, :product_id, :name, :phone, :email, 'new')",
            [
                'user_id' => $this->userId($user),
                'product_id' => $productId,
                'name' => $name !== '' ? $name : 'Клієнт',
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
            ]
        );

        return $this->result($ok, 'Запит збережено. Повідомимо, коли товар буде доступний.');
    }

    private function createCalculationRequest(array $data, ?array $user): array
    {
        $name = $this->value($data, 'name', $user['name'] ?? '');
        $phone = $this->value($data, 'phone', $user['phone'] ?? '');
        $email = $this->value($data, 'email', $user['email'] ?? '');
        $projectType = $this->value($data, 'project_type');
        $materials = $this->value($data, 'materials_list');
        $comment = $this->value($data, 'comment');
        $attachments = $this->saveCalculationAttachments();
        if ($attachments !== []) {
            $comment = trim($comment . "\n\nФайли до розрахунку:\n" . implode("\n", $attachments));
        }

        if ($name === '' || $phone === '' || ($materials === '' && $comment === '')) {
            return ['ok' => false, 'message' => 'Для розрахунку вкажіть імʼя, телефон і список матеріалів або додайте файл.'];
        }

        $ok = Database::execute(
            "INSERT INTO rc_calculation_requests (user_id, name, phone, email, project_type, materials_list, comment_text, status, source)
             VALUES (:user_id, :name, :phone, :email, :project_type, :materials_list, :comment_text, 'new', 'site')",
            [
                'user_id' => $this->userId($user),
                'name' => $name,
                'phone' => $phone,
                'email' => $email !== '' ? $email : null,
                'project_type' => $projectType !== '' ? $projectType : null,
                'materials_list' => $materials !== '' ? $materials : null,
                'comment_text' => $comment !== '' ? $comment : null,
            ]
        );

        return $this->result($ok, 'Заявку на розрахунок прийнято. Файли збережено, менеджер підготує кошторис.');
    }

    private function createChatMessage(array $data, ?array $user): array
    {
        $message = $this->value($data, 'message');
        $name = $this->value($data, 'name', $user['name'] ?? 'Клієнт');
        $contact = $this->value($data, 'contact', $user['phone'] ?? ($user['email'] ?? ''));

        if ($message === '') {
            return ['ok' => false, 'message' => 'Повідомлення порожнє.'];
        }

        $answer = $this->answerChatQuestion($message);
        return ['ok' => true, 'message' => $answer, 'answer' => $answer];
    }


    private function saveCalculationAttachments(): array
    {
        $fileBag = $_FILES['calculation_files'] ?? null;
        if (!is_array($fileBag) || empty($fileBag['name'])) {
            return [];
        }

        $names = is_array($fileBag['name']) ? $fileBag['name'] : [$fileBag['name']];
        $tmpNames = is_array($fileBag['tmp_name']) ? $fileBag['tmp_name'] : [$fileBag['tmp_name']];
        $errors = is_array($fileBag['error']) ? $fileBag['error'] : [$fileBag['error']];
        $sizes = is_array($fileBag['size']) ? $fileBag['size'] : [$fileBag['size']];

        $dir = __DIR__ . '/../uploads/calculations/' . date('Y/m');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $allowed = ['pdf','doc','docx','jpg','jpeg','png','webp'];
        $saved = [];
        foreach ($names as $i => $originalName) {
            if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = (string)($tmpNames[$i] ?? '');
            $size = (int)($sizes[$i] ?? 0);
            if ($tmp === '' || !is_uploaded_file($tmp) || $size <= 0 || $size > 10 * 1024 * 1024) {
                continue;
            }
            $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true) || !$this->allowedAttachmentMime($tmp, $ext)) {
                continue;
            }
            $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo((string)$originalName, PATHINFO_FILENAME)) ?: 'file';
            $fileName = date('His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeName . '.' . $ext;
            $path = $dir . '/' . $fileName;
            if (move_uploaded_file($tmp, $path)) {
                $saved[] = '/uploads/calculations/' . date('Y/m') . '/' . $fileName;
            }
        }
        return $saved;
    }

    private function allowedAttachmentMime(string $tmp, string $ext): bool
    {
        if (!is_file($tmp)) {
            return false;
        }
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return @getimagesize($tmp) !== false;
        }
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }
        $allowed = [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        ];
        return $mime === '' || in_array(strtolower($mime), $allowed[$ext] ?? [], true);
    }

    private function answerChatQuestion(string $message): string
    {
        $text = mb_strtolower(trim($message), 'UTF-8');
        $company = [];
        try {
            foreach (Database::fetchAll("SELECT setting_key, setting_value FROM rc_site_settings") as $row) {
                $company[(string)$row['SETTING_KEY']] = (string)$row['SETTING_VALUE'];
            }
        } catch (Throwable) {
            $company = [];
        }
        $phone = $company['phone_label'] ?? '+38 (093) 727-85-61';
        $email = $company['email'] ?? 'fatoha359@gmail.com';
        $address = $company['address'] ?? 'м. Київ, вул. Куренівська, 15';
        $worktime = $company['worktime'] ?? '8:00 - 20:00, без вихідних';

        $catalogHints = [];
        try {
            foreach (Database::fetchAll("SELECT name, stock_qty, price FROM rc_products WHERE is_active = 1 ORDER BY id DESC FETCH FIRST 8 ROWS ONLY") as $product) {
                $catalogHints[] = (string)($product['NAME'] ?? '') . ' — ' . (string)($product['STOCK_QTY'] ?? '0') . ' шт., ' . number_format((float)($product['PRICE'] ?? 0), 0, '.', ' ') . ' грн';
            }
        } catch (Throwable) {
            $catalogHints = [];
        }

        $hasAny = static fn(array $words): bool => array_reduce($words, static fn(bool $carry, string $word): bool => $carry || str_contains($text, $word), false);

        if ($hasAny(['достав', 'нова пошта', 'новой почт', 'відділен', 'отделен', 'поштомат', 'delivery', 'делівері', 'курʼєр', 'курьер'])) {
            return 'Доставка доступна самовивозом зі складу (' . $address . '), курʼєром, Новою поштою або Delivery. Для доставки у відділення оберіть місто та відділення, для адресної доставки вкажіть місто, вулицю, будинок і коментар. Великогабаритні матеріали менеджер додатково погоджує за часом і вартістю.';
        }
        if ($hasAny(['оплат', 'оплата', 'liqpay', 'wayforpay', 'карт', 'карта', 'онлайн', 'платіж', 'платеж'])) {
            return 'Доступні: оплата при отриманні, LiqPay/WayForPay, оплата після підтвердження менеджером і рахунок для ФОП/юридичних осіб. Якщо онлайн-оплата успішна, статус замовлення має змінитися на “Оплачено”; якщо статус не оновився, повідомте номер замовлення менеджеру.';
        }
        if ($hasAny(['рахунок', 'счет', 'юр', 'юрид', 'фоп', 'тов', 'безгот', 'реквізит', 'реквизит'])) {
            return 'Для ФОП та юридичних осіб відкрийте розділ “Опт”, залиште реквізити компанії та контактну особу. Після перевірки менеджер сформує рахунок і підкаже умови безготівкової оплати.';
        }
        if ($hasAny(['наяв', 'склад', 'залиш', 'остат', 'ціна', 'цена', 'товар', 'артикул'])) {
            $suffix = $catalogHints !== [] ? ' Приклади товарів зараз у каталозі: ' . implode('; ', array_slice($catalogHints, 0, 3)) . '.' : '';
            return 'Наявність, ціна та характеристики беруться з каталогу й складських залишків. У картці товару видно ціну, одиницю продажу, статус і параметри. Напишіть назву або артикул — я спробую зорієнтувати за каталогом.' . $suffix;
        }
        if ($hasAny(['розрах', 'коштор', 'смет', 'матеріал', 'материал', 'список', 'площа', 'площадь'])) {
            return 'Для розрахунку матеріалів увійдіть у кабінет і заповніть форму “Розрахунок матеріалів”: можна вказати тип робіт, список матеріалів і прикріпити PDF, DOCX або фото. Персонал підготує кошторис і звʼяжеться з вами.';
        }
        if ($hasAny(['графік', 'график', 'работ', 'прац', 'адрес', 'контакт', 'телефон', 'пошта', 'почта'])) {
            return 'Контакти RungoCraft: телефон ' . $phone . ', email ' . $email . ', адреса ' . $address . '. Графік роботи: ' . $worktime . '.';
        }
        if ($hasAny(['поверн', 'возврат', 'обмін', 'обмен', 'гарант'])) {
            return 'Повернення, обмін і гарантійні питання узгоджуються з менеджером за типом товару, станом упаковки та документами замовлення. Збережіть номер замовлення і зверніться в підтримку.';
        }
        if ($hasAny(['реєстр', 'регистра', 'вхід', 'вход', 'пароль', 'код'])) {
            return 'Для клієнтських заявок, розрахунку, опту та звернень потрібно увійти або зареєструватися. Email-вхід захищений паролем і кодом з листа, телефон — кодом підтвердження.';
        }

        return 'Я не зміг точно визначити тему питання. Напишіть у підтримку через кабінет або зверніться напряму: ' . $phone . ', ' . $email . '. Можна також уточнити товар, місто доставки або спосіб оплати — тоді відповідь буде точнішою.';
    }

    private function updateRequestStatus(array $data, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав для зміни статусу заявки.'];
        }

        $type = (string)($data['request_type'] ?? '');
        $id = (int)($data['request_id'] ?? 0);
        $status = (string)($data['status'] ?? 'processing');

        $allowedStatuses = ['new', 'processing', 'done', 'cancelled'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'processing';
        }

        $tables = $this->requestTables();

        if ($id <= 0 || !isset($tables[$type])) {
            return ['ok' => false, 'message' => 'Заявку не знайдено.'];
        }

        $ok = Database::execute(
            'UPDATE ' . $tables[$type] . ' SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['status' => $status, 'id' => $id]
        );

        return $this->result($ok, 'Статус заявки оновлено.');
    }

    private function deleteRequest(array $data, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав для видалення заявки.'];
        }

        $type = (string)($data['request_type'] ?? '');
        $id = (int)($data['request_id'] ?? 0);
        $tables = $this->requestTables();
        if ($id <= 0 || !isset($tables[$type])) {
            return ['ok' => false, 'message' => 'Заявку не знайдено.'];
        }

        $deleted = Database::executeAffected(
            'DELETE FROM ' . $tables[$type] . ' WHERE id = :id',
            ['id' => $id]
        );

        if ($deleted === null) {
            return ['ok' => false, 'message' => 'Заявку не видалено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        return ['ok' => true, 'message' => $deleted > 0 ? 'Заявку видалено.' : 'Запис уже відсутній.', 'return_to' => '/admin#requests'];
    }

    private function bulkDeleteRequests(array $data, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав для масового видалення заявок.'];
        }

        $type = (string)($data['request_type'] ?? '');
        $dateFrom = trim((string)($data['date_from'] ?? ''));
        $dateTo = trim((string)($data['date_to'] ?? ''));
        $deleteAll = !empty($data['delete_all']);
        $confirmed = (string)($data['confirm_bulk_delete'] ?? '') === 'yes';
        $tables = $this->requestTables();

        if (!isset($tables[$type])) {
            return ['ok' => false, 'message' => 'Оберіть таблицю заявок для очищення.'];
        }
        if (!$confirmed) {
            return ['ok' => false, 'message' => 'Підтвердіть масове видалення прапорцем.'];
        }
        if (!$deleteAll && $dateFrom === '' && $dateTo === '') {
            return ['ok' => false, 'message' => 'Оберіть дату “з/по” або поставте прапорець “видалити всі записи цієї таблиці”.'];
        }
        if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            return ['ok' => false, 'message' => 'Некоректна дата “з”.'];
        }
        if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            return ['ok' => false, 'message' => 'Некоректна дата “по”.'];
        }

        $where = [];
        $params = [];
        if (!$deleteAll) {
            if ($dateFrom !== '') {
                $where[] = "created_at >= TO_DATE(:date_from, 'YYYY-MM-DD')";
                $params['date_from'] = $dateFrom;
            }
            if ($dateTo !== '') {
                $where[] = "created_at < TO_DATE(:date_to, 'YYYY-MM-DD') + 1";
                $params['date_to'] = $dateTo;
            }
        }

        $sql = 'DELETE FROM ' . $tables[$type];
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $deleted = Database::executeAffected($sql, $params);
        if ($deleted === null) {
            return ['ok' => false, 'message' => 'Записи не видалено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        return ['ok' => true, 'message' => 'Видалено записів: ' . $deleted . '.', 'return_to' => '/admin#requests'];
    }

    private function latest(string $table): array
    {
        return Database::fetchAll(
            "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC FETCH FIRST 30 ROWS ONLY"
        );
    }

    private function requestTables(): array
    {
        return [
            'callback' => 'rc_callback_requests',
            'calculation' => 'rc_calculation_requests',
            'ticket' => 'rc_support_tickets',
            'product_question' => 'rc_product_questions',
            'availability' => 'rc_availability_requests',
            'chat' => 'rc_chat_messages',
        ];
    }

    private function countTable(string $table): int
    {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM {$table}"
        );
        return (int)($row['CNT'] ?? 0);
    }

    private function userId(?array $user): ?int
    {
        return !empty($user['id']) ? (int)$user['id'] : null;
    }

    private function canManage(?array $user): bool
    {
        return $user && in_array((string)($user['role'] ?? ''), ['admin', 'manager'], true);
    }

    private function value(array $data, string $key, string $default = ''): string
    {
        return trim((string)($data[$key] ?? $default));
    }

    private function latestRequestId(string $table, string $name, string $phone): int
    {
        $allowed = ['rc_callback_requests', 'rc_calculation_requests', 'rc_support_tickets', 'rc_chat_messages'];
        if (!in_array($table, $allowed, true)) {
            return 0;
        }
        $row = Database::fetchOne(
            "SELECT id
             FROM {$table}
             WHERE name = :name
               AND (:phone_value IS NULL OR phone = :phone_value)
             ORDER BY created_at DESC, id DESC
             FETCH FIRST 1 ROWS ONLY",
            ['name' => $name, 'phone_value' => $phone !== '' ? $phone : null]
        );
        return (int)($row['ID'] ?? 0);
    }

    private function result(bool $ok, string $success): array
    {
        return [
            'ok' => $ok,
            'message' => $ok ? $success : 'Дію не виконано: ' . (Database::lastError() ?? 'невідома помилка'),
        ];
    }
}
