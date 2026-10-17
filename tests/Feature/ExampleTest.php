<?php

test('root redirects to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});
