<nav>nav:{{ implode(',', $config->getPages() ?? []) }}</nav>
{{ $slot }}
@include('partials.footer')
<x-flyo::debug-info />
