<x-ui.tabs id="admin-optimizer" :tabs="['personality' => 'Persönlichkeit', 'skills' => 'Skills', 'prompts' => 'Prompts', 'planning' => 'Planung', 'reviews' => 'Qualitätsprüfung', 'network' => 'Netzwerk']" active="personality">
    <x-ui.tab-panel name="personality">@include('admin.pages.optimizer-personas')</x-ui.tab-panel>
    <x-ui.tab-panel name="skills">@include('admin.optimizer-extras')</x-ui.tab-panel>
    <x-ui.tab-panel name="prompts">@include('admin.pages.optimizer-prompts')</x-ui.tab-panel>
    <x-ui.tab-panel name="planning">@include('admin.pages.optimizer-planning')</x-ui.tab-panel>
    <x-ui.tab-panel name="reviews">@include('admin.pages.optimizer-reviews')</x-ui.tab-panel>
    <x-ui.tab-panel name="network">@include('admin.pages.optimizer-policies')</x-ui.tab-panel>
</x-ui.tabs>
