<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Http\Request;
use ProjectHub\Http\Response;

final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        // 專案列表於階段 5 實作；目前只顯示登入後首頁
        return $this->render('home', ['title' => '首頁']);
    }
}
