<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    public function render(): View
    {
        return view(auth()->user()?->isAdmin() ? 'layouts.admin' : 'layouts.customer');
    }
}
