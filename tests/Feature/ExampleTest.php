<?php

it('returns a successful response from the health endpoint', function () {
    $this->get('/up')->assertOk();
});
