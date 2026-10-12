<?php
declare(strict_types=1);

namespace Tablo;

final class WebPostAdmission
{
    public static function admit(PageResponse $page): ?\Phalcon\Http\Response
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $csrf = $_POST['_csrf'] ?? null;
            if (($_SERVER['CONTENT_LENGTH'] ?? 0) > 16_384) {
                return $page->render('error', ['title' => 'Слишком большой запрос', 'message' => 'Данные формы превышают 16 КБ.'], 413);
            }
            if (!is_string($csrf) || !is_string($_SESSION['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $csrf)) {
                return $page->render('error', ['title' => 'Форма устарела', 'message' => 'Обновите страницу и повторите действие.'], 419);
            }
        }
        return null;
    }
}
