<?php

namespace App\Http\Controllers;

use App\Mail\NotifySubscriberForNewArticle;
use App\Models\Article;
use App\Models\Category;
use App\Models\Keyword;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $articles = Article::getPaginate($request);
        return view("frontend.articles.index", compact('articles'));
    }

    public function show($articleId, $articleHeading = '')
    {
        $article = Article::where('id', $articleId)
            ->published()
            ->notDeleted()
            ->with(['user', 'category', 'keywords',])
            ->first();

        if (is_null($article)) {
            return redirect()->route('home')->with('warningMsg', 'Article not found');
        }

        $article->isEditable = $this->isEditable($article);

        $relatedArticles = $this->getRelatedArticles($article);

        return view("frontend.articles.show", compact('article', 'relatedArticles'));
    }

    private function isEditable(Article $article)
    {
        if (!auth()->check()) {
            return false;
        }
        $isAdmin = auth()->user()->hasRole(['owner', 'admin']);
        $isAuthor = $article->user->id == auth()->user()->id;
        return auth()->check() && ($isAdmin || $isAuthor);
    }

    private function getRelatedArticles(Article $article)
    {
        return Article::where('category_id', $article->category->id)
            ->where('id', '!=', $article->id)
            ->published()
            ->latest()
            ->take(3)
            ->get();
    }

    public function edit($articleId)
    {
        $article = Article::find($articleId);

        if (is_null($article)) {
            return redirect()->route('home')->with('errorMsg', 'Article not found');
        }

        if ($article->hasAuthorization(Auth::user())) {
            return redirect()->route('home')->with('errorMsg', 'Unauthorized request');
        }

        $keywords = implode(' ', $article->keywords->pluck('name')->toArray());
        $article = json_decode(json_encode($article));
        $article->keywords = $keywords;

        $categories = Category::active()->get();
        return view('backend.article_edit', compact('categories', 'article'));
    }

    public function update(Request $request, $articleId)
    {
        $article = Article::find($articleId);
        if (is_null($article)) {
            return response()->json(['errorMsg' => 'Article not found'], Response::HTTP_NOT_FOUND);
        }

        if ($article->hasAuthorization(Auth::user())) {
            return response()->json(['errorMsg' => 'Unauthorized request'], Response::HTTP_UNAUTHORIZED);
        }
        $updatedArticle = $request->only(['heading', 'content', 'category_id', 'language']);
        $updatedArticle['is_comment_enabled'] = $request->input('is_comment_enabled');
        $keywordsToAttach = array_unique(explode(' ', $request->get('keywords')));
        try {
            $article->update($updatedArticle);
            //remove all keywords then add all keywords from input
            $article->keywords()->detach();
            foreach ($keywordsToAttach as $keywordToAttach) {
                $newKeyword = Keyword::firstOrCreate(['name' => $keywordToAttach]);
                $article->keywords()->attach($newKeyword->id);
            }
        } catch (\PDOException $e) {
            Log::error($this->getLogMsg($e));
            return response()->json(['errorMsg' => $this->getMessage($e)], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        session()->flash('successMsg', 'Article updated successfully!');
        return response()->json(['redirect_url' => redirect()->route('admin-articles')->getTargetUrl()]);
    }

    public function create()
    {
        $categories = Category::where('is_active', 1)->get();
        return view('backend.article_create', compact('categories'));
    }

    public function store(Request $request)
    {
        $clientIP = $_SERVER['REMOTE_ADDR'];

        $newArticle = $request->only(['heading', 'content', 'category_id', 'language']);
        $newArticle['is_comment_enabled'] = $request->input('is_comment_enabled');
        $newAddress = ['ip' => $clientIP];

        try {
            //Create new article
            $newArticle['address_id'] = $newAddress->id;
            $newArticle['published_at'] = new \DateTime();
            $newArticle['user_id'] = Auth::user()->id;
            $newArticle = Article::create($newArticle);
            //add keywords
            $keywordsToAttach = array_unique(explode(' ', $request->get('keywords')));
            foreach ($keywordsToAttach as $keywordToAttach) {
                $newKeyword = Keyword::firstOrCreate(['name' => $keywordToAttach]);
                $newArticle->keywords()->attach($newKeyword->id);
            }
            //Notify all subscriber about the new article
            foreach (User::getSubscribedUsers() as $subscriber) {
                Mail::to($subscriber->email)->queue(new NotifySubscriberForNewArticle($newArticle, $subscriber));
            }
        } catch (\PDOException $e) {
            Log::error($this->getLogMsg($e));
            return response()->json(['errorMsg' => $this->getMessage($e)], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        session()->flash('successMsg', 'Article published successfully!');
        return response()->json(['redirect_url' => redirect()->route('admin-articles')->getTargetUrl()]);
    }

    public function search(Request $request)
    {
        $this->validate($request, ['query_string' => 'required']);

        $queryString = $request->get('query_string');

        $articles = Article::published()
            ->notDeleted()
            ->where('heading', 'LIKE', "%$queryString%")
            ->orWhere('content', 'LIKE', "%$queryString%")
            ->orWhereHas('keywords', function (Builder $keywords) use ($queryString) {
                return $keywords->where('name', 'LIKE', "%$queryString%")
                    ->where('is_active', 1);
            })
            ->with('category', 'keywords', 'user')
            ->latest()
            ->paginate(config('blog.item_per_page'));

        $articles->setPath(url("search/?query_string=$queryString"));

        $searched = new \stdClass();
        $searched->articles = $articles;
        $searched->query = $queryString;

        return view("frontend.articles.search_result", compact('searched'));
    }
}
