<?php

declare(strict_types=1);

/** Serve status pages to anonymous visitors and accept the password of protected ones. */

namespace App\Http\Controllers\StatusPages;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatusPages\UnlockStatusPageRequest;
use App\Models\StatusPage;
use App\Services\StatusPages\PublicStatusPages;
use App\Services\StatusPages\StatusPageUnlocks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** A locked page sends only its address, so title and components stay hidden until the password is entered. */
final class PublicStatusPageController extends Controller
{
    /**
     * @param PublicStatusPages $pages Public lookup and projection.
     * @param StatusPageUnlocks $unlocks Session record of entered passwords.
     */
    public function __construct(private readonly PublicStatusPages $pages, private readonly StatusPageUnlocks $unlocks)
    {
    }

    /**
     * @param Request $request Visitor request with its session.
     * @param string $slug Public address segment.
     * @return Response The page, or its password form while locked.
     */
    public function show(Request $request, string $slug): Response
    {
        $page = $this->published($slug);

        if (!$this->unlocks->allows($request->session(), $page)) {
            return Inertia::render('StatusPages/Unlock', ['slug' => $page->slug]);
        }

        return Inertia::render('StatusPages/Public', ['page' => $this->pages->present($page)]);
    }

    /**
     * @param UnlockStatusPageRequest $request Bounded password.
     * @param string $slug Public address segment.
     * @return RedirectResponse The page when the password is right.
     * @throws ValidationException On key `password` when it is wrong.
     */
    public function unlock(UnlockStatusPageRequest $request, string $slug): RedirectResponse
    {
        $page = $this->published($slug);

        // A page without a password has nothing to unlock, so any submission simply reaches it.
        if ($page->password_hash !== null && !$this->unlocks->attempt($request->session(), $page, $request->string('password')->toString())) {
            throw ValidationException::withMessages(['password' => 'This password is not correct.']);
        }

        return redirect()->route('status-pages.public', ['slug' => $page->slug]);
    }

    /**
     * @param string $slug Public address segment.
     * @return StatusPage Published page.
     */
    private function published(string $slug): StatusPage
    {
        $page = $this->pages->find($slug);
        abort_if($page === null, 404);

        return $page;
    }
}
