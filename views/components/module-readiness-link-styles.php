<?php // Scoped to M0 links; existing outline buttons use the lower-contrast brand color. ?>
<style>
    a.ys-module-link {
        color: var(--ys-primary-text); border-color: var(--ys-primary-text);
        background: var(--ys-surface); min-height: 44px;
    }
    a.ys-module-link:hover {
        color: var(--ys-primary-text); background: var(--ys-surface); text-decoration: underline;
    }
    a.ys-module-link:focus-visible {
        outline: 3px solid var(--ys-primary-text); outline-offset: 3px; box-shadow: none;
    }
</style>
