<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BudgetPlanController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            app('view')->share('title', 'Budget Plans');
            app('view')->share('mainTitleIcon', 'fa-book');

            return $next($request);
        });
    }

    /**
     * @return Factory|View
     */
    public function index(): Factory|\Illuminate\Contracts\View\View
    {
        $directory = storage_path('budget-plans');
        $plans     = [];

        if (is_dir($directory)) {
            $files = glob($directory . '/*.md');
            if (false !== $files) {
                foreach ($files as $file) {
                    $filename = basename($file);
                    $name     = str_replace(['_', '.md'], [' ', ''], $filename);
                    $modified = filemtime($file);

                    $plans[]  = [
                        'filename' => $filename,
                        'name'     => $name,
                        'size'     => filesize($file),
                        'modified' => false !== $modified ? date('F j, Y', $modified) : 'Unknown',
                    ];
                }
            }
        }

        usort($plans, static fn (array $a, array $b): int => strcmp($b['filename'], $a['filename']));

        return view('budget-plans.index', compact('plans'));
    }

    /**
     * @return Factory|View
     */
    public function show(string $filename): Factory|\Illuminate\Contracts\View\View
    {
        $filename  = basename($filename);

        if (!str_ends_with($filename, '.md')) {
            throw new NotFoundHttpException();
        }

        $path = storage_path('budget-plans/' . $filename);

        if (!file_exists($path)) {
            throw new NotFoundHttpException();
        }

        $content   = file_get_contents($path);
        if (false === $content) {
            throw new NotFoundHttpException();
        }

        $name      = str_replace(['_', '.md'], [' ', ''], $filename);
        $subTitle  = $name;

        return view('budget-plans.show', compact('content', 'subTitle', 'filename'));
    }
}
