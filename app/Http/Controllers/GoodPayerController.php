<?php

namespace App\Http\Controllers;

use App\Services\ReportService;

class GoodPayerController extends Controller
{
    public function index()
    {
        $complex = $this->currentComplex();

        if (! $complex) {
            return view('good-payers.index', ['enabled' => false, 'payers' => collect(), 'complex' => null]);
        }

        $payers = $complex->good_payer_enabled
            ? (new ReportService($complex))->goodPayers(20)
            : collect();

        return view('good-payers.index', [
            'enabled' => $complex->good_payer_enabled,
            'payers' => $payers,
            'complex' => $complex,
        ]);
    }
}
