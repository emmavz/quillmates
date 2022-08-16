<?php

namespace App\Http\Controllers\API\Front;

use Illuminate\Http\Request;
use App\Http\Controllers\API\Front\BaseController as BaseController;
use App\Jobs\SendUserNotification;
use App\Models\Article;
// use App\Models\ArticleUserPaid;
// use App\Models\UserProfileInvestment;
use App\Models\User;
use App\Models\Follow;
use App\Models\Tag;
use App\Models\ArticleShare;
use App\Models\UserInvestment;
use App\Models\BondingCurve;
use DB;
use Auth;
use App\Models\UserNotification;
use Validator;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class ApiController extends BaseController
{
    /**
     * Display a listing of the articles.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_article_list_and_view(Request $request)
    {
        $articles = Article::with('user:id,name,pfp', 'tags:name')->select('id', 'title', 'date_posted', 'image_url', 'user_id', 'price', 'liquidation_days')
            ->addSelect($this->getLiquidationDaysQuery())
            ->withCount('is_paid_by_user', 'is_paid_by_referrals')
            // ->withCount(['total_invested' => function ($query) {
            //     return $query->select(DB::raw("SUM(price)"));
            // }])
            ->limit($request->limit)->skip($request->offset)->where('is_published', 1)->orderBy('date_posted', 'DESC')->get();

        return $this->sendResponse($articles);
    }

    /**
     * Display a listing of the tags.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_tags(Request $request)
    {
        $tags = Tag::get();

        return $this->sendResponse($tags);
    }

    /**
     * Display article homepage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_article_homepage(Request $request)
    {

        $article = Article::with(['user:id,name,pfp'])->select('id', 'title', 'date_posted', 'image_url', 'price',  'description', 'user_id', 'share_to_read', 'liquidation_days')
            ->addSelect($this->getLiquidationDaysQuery())
            ->withCount(['total_reads', 'total_shares', 'is_paid_by_user', 'is_paid_by_referrals'])->where('id', $request->article_id)->where('is_published', 1);

        // Coming from article published page
        if ($request->article_published) {
            $article->where('user_id', auth()->user()->id);
        }

        $article = $article->firstOrFail();
        $article->total_investments = $article->total_invested->sum('price');

        // Get user profile
        $request->merge(['user_id' => $article->user_id]);
        $user = $this->get_user_profile($request, 0);

        // Check if article is free
        $isArticleFreeArr = $this->isArticleFree($request, $article);
        $isArticleFree = $isArticleFreeArr[0];
        $liquidation_days = $isArticleFreeArr[1];

        // Insert new share in database and increment in total shares count
        if ($this->share_article($request)) $article->total_shares_count++;

        $referral_token = auth()->user()->referral_token;

        return $this->sendResponse([$article, $user, $isArticleFree, $liquidation_days, $referral_token]);
    }

    /**
     * Display full article.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_full_article(Request $request)
    {

        $article = Article::with('user:id,name,pfp')->select('id', 'title', 'date_posted', 'image_url', 'price', 'description', 'user_id', 'content', 'share_to_read', 'liquidation_days')->withCount('total_reads', 'total_shares')->where('id', $request->article_id)->where('is_published', 1)->firstOrFail();

        $request->merge(['user_id' => $article->user_id]);

        $userStats = null;
        if (auth()->user()->id != $article->user_id) {
            $userStats = $this->get_other_user_investments($request, false);
        }

        // Check if article is free
        $isArticleFreeArr = $this->isArticleFree($request, $article);
        $isArticleFree = $isArticleFreeArr[0];
        $liquidation_days = $isArticleFreeArr[1];

        if (!$isArticleFree) {
            return $this->sendResponse([['is_article_free' => false]]);
        }

        $user = $this->get_user_profile($request, 0);

        // Insert new read in database and increment in total reads count
        if ($this->read_article($request)) $article->total_reads_count++;
        // Insert new share in database and increment in total shares count
        if ($this->share_article($request)) $article->total_shares_count++;

        $referral_token = auth()->user()->referral_token;

        return $this->sendResponse([$article, $user, $userStats, $isArticleFree, $liquidation_days, $referral_token]);
    }

    /**
     * Display user profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_user_profile(Request $request, $shouldReturnResponse = 1)
    {
        $user = User::select('id', 'name', 'bio', 'pfp', DB::raw('CASE WHEN EXISTS(SELECT * FROM follows WHERE users.id = followed_id AND follows.follower_id = ' . auth()->user()->id . ') THEN 1 ELSE 0 END AS is_followed'))->withCount(['followers', 'followed'])->where('id', $request->user_id)->firstOrFail();

        if ($shouldReturnResponse) {
            return $this->sendResponse($user);
        } else {
            return $user;
        }
    }

    /**
     * Display user profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_user_profile_with_balance(Request $request, $shouldReturnResponse = 1)
    {
        $userProfile = $this->get_user_profile($request, 0);
        return $this->sendResponse([$userProfile, auth()->user()->balance]);
    }

    /**
     * Display auth user profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_auth_user_profile(Request $request, $shouldReturnResponse = 1)
    {
        $user = User::select('id', 'name', 'bio', 'pfp', 'balance', 'referral_token', DB::raw('CASE WHEN EXISTS(SELECT * FROM follows WHERE users.id = followed_id AND follows.follower_id = ' . auth()->user()->id . ') THEN 1 ELSE 0 END AS is_followed'))->withCount(['followers', 'followed'])->where('id', auth()->user()->id)->firstOrFail();

        if ($shouldReturnResponse) {
            return $this->sendResponse($user);
        } else {
            return $user;
        }
    }
    /**
     * Display article recommendations.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_recommendations(Request $request)
    {
        $articles = Article::with('user:id,name,pfp', 'tags:name')->select('id', 'title', 'date_posted', 'image_url', 'user_id', 'price')
            // ->withCount(['total_invested' => function ($query) {
            //     return $query->select(DB::raw("SUM(price)"));
            // }])
            ->orderBy('date_posted', 'DESC')->limit($request->limit)->skip($request->offset)->where('is_published', 1)->get();

        return $this->sendResponse($articles);
    }

    /**
     * Display articles search results.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function search_articles(Request $request)
    {
        $articles = Article::with('user:id,name,pfp', 'tags:name')->select('id', 'title', 'date_posted', 'image_url', 'user_id', 'price')
            // ->withCount(['total_invested' => function ($query) {
            //     return $query->select(DB::raw("SUM(price)"));
            // }])
            ->orderBy('date_posted', 'DESC')->where('title', 'LIKE', '%' . $request->q . '%')->where('is_published', 1)->get();

        return $this->sendResponse($articles);
    }

    /**
     * Display authors search results.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function search_authors(Request $request)
    {
        $users = User::select('id', 'name', 'bio', 'pfp', DB::raw('CASE WHEN EXISTS(SELECT * FROM follows WHERE users.id = followed_id AND follows.follower_id = ' . $request->follower_id . ') THEN 1 ELSE 0 END AS is_followed'))->where('name', 'LIKE', '%' . $request->q . '%')->where('id', '!=', $request->follower_id)->latest()->get();

        return $this->sendResponse($users);
    }

    /**
     * Follow other user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function do_follow_toggle(Request $request)
    {

        $this->validate_fields($request, [
            'followed_id' => ['required', 'integer', 'exists:users,id']
        ]);

        $follow = Follow::where('follower_id', auth()->user()->id)->where('followed_id', $request->followed_id)->first();
        if ($follow) {
            $follow->delete();
        } else {
            $follow = new Follow;
            $follow->follower_id = auth()->user()->id;
            $follow->followed_id = $request->followed_id;
            $follow->save();
        }

        return $this->sendResponse([]);
    }

    /**
     * Display notifications.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_notifications(Request $request)
    {

        $userNotifications = UserNotification::with('notification:id,text')->select('id', 'read_at', 'notification_id')->where('user_id', auth()->user()->id)->latest()->get();

        return $this->sendResponse($userNotifications);
    }

    /**
     * read notifications.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function read_notification(Request $request)
    {
        $this->validate_fields($request, [
            'notification_id' => ['required', 'integer', 'exists:user_notifications,id']
        ]);

        $now = Carbon::now();

        UserNotification::where('id', $request->notification_id)->where('user_id', auth()->user()->id)->update([
            'read_at' => $now
        ]);

        return $this->sendResponse([$now]);
    }

    /**
     * Display a listing of the draft articles.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_draft_articles(Request $request)
    {
        $articles = Article::with(['user' => function ($query) {
            $query->select('id', 'name', 'pfp');
        }])->whereHas('user', function ($query) use ($request) {
            $query->where('id', auth()->user()->id);
        })->select('id', 'title', 'date_posted', 'image_url', 'user_id')->latest()->where('is_published', 0)->get();

        return $this->sendResponse($articles);
    }

    /**
     * Display a listing of the user profile articles.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_user_profile_articles(Request $request)
    {
        $articlesSelect = ['id', 'title', 'date_posted', 'image_url', 'user_id'];
        $articles = Article::with('user:id,name,pfp')->whereHas('user', function ($query) use ($request) {
            $query->where('id', $request->user_id);
        });

        // Show tags and price only for other user profiles and not for auth user
        if (auth()->user()->id != $request->user_id) {
            $articles->with('tags:name');
            array_push($articlesSelect, 'price');
            // $articles->withCount(['total_invested' => function ($query) {
            //     return $query->select(DB::raw("SUM(price)"));
            // }]);
        }

        $articles->select($articlesSelect);

        $articles = $articles->orderBy('date_posted', 'DESC')->where('is_published', 1)->get();

        return $this->sendResponse($articles);
    }

    /** Display user draft article for editing.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_user_draft_article(Request $request)
    {
        $article = Article::with('tags')->where('user_id', auth()->user()->id)->where('id', $request->article_id)->firstOrFail();

        return $this->sendResponse($article);
    }

    /**
     * store article.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store_article(Request $request)
    {
        $article = null;
        $oarticle = null;
        $alreadyPublished = false;
        if ($request->article_id) {
            $article = Article::where('user_id', auth()->user()->id)->where('id', $request->article_id)->first();
            if (!$article) {
                $article = new Article();
            } else {
                $oarticle = clone $article;
                if ($article->is_published) $alreadyPublished = true;
            }
        } else {
            $article = new Article();
        }

        $request->merge(['tags' => array_filter(explode(',', $request->tags[0]))]);

        $rules = [
            'title'   => ['required', 'string'],
            'content' => ['nullable', 'required_if:should_publish,1', 'string'],
            'image_url' => ['nullable', Rule::RequiredIf(function () use ($article) {
                return !$article;
            }), 'mimes:' . config('website.imgformats')],
            'description' => ['nullable', 'required_if:should_publish,1', 'string', 'max:' . config('website.max_article_description_length')],
            'price'   => ['nullable', 'required_if:should_publish,1',  'numeric', 'gte:0'],
            'theta'   => ['nullable', 'required_if:should_publish,1', 'numeric', 'gte:0', 'lte:100'],
            'liquidation_days' => ['nullable', 'required_if:should_publish,1', 'numeric', 'gte:0'],
            'share_to_read' => ['required', 'boolean'],
            'tags' => ['nullable', 'required_if:should_publish,1', 'array', 'max:' . config('website.max_article_tags'), 'exists:tags,id'],
            'should_publish' => ['required', 'boolean'],
        ];

        $fields = $this->validate_fields($request, $rules);

        $fields = Article::storeFiles($request, $fields, $oarticle);

        $article->title = $fields['title'];
        $article->description = $fields['description'];
        if ($fields['image_url']) $article->image_url = $fields['image_url'];
        // $article->is_published = 0;
        $article->user_id = auth()->user()->id;
        $article->content = $fields['content'];
        $article->price = $fields['price'];
        $article->theta = $fields['theta'];
        $article->liquidation_days = $fields['liquidation_days'];
        $article->share_to_read = $fields['share_to_read'];

        if ($fields['should_publish'] && !$alreadyPublished) {
            $article->is_published = 1;
            $article->date_posted = Carbon::now();
        }

        $article->save();

        $article->tags()->sync($fields['tags']);

        if ($oarticle) Article::deleteFiles($oarticle, $request);

        if (count(auth()->user()->articles) == 1 && !$alreadyPublished) {
            SendUserNotification::dispatch(['text' => 'Congratulations on your first article: <a :to="{ name: \'article_homepage\', params: { articleId: ' . $article->id . ' } }"><b>' . $article->title . '</b></a>!', 'user_id' => auth()->user()->id]);
        }

        return $this->sendResponse([['id' => $article->id]]);
    }

    /**
     * delete article.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function delete_article(Request $request)
    {
        $article = Article::where('user_id', auth()->user()->id)->where('id', $request->article_id)->firstOrFail();
        $article->delete();
        return $this->sendResponse([]);
    }

    /** Pay Article.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function pay_article(Request $request)
    {
        $article = Article::where('id', $request->article_id)->where('is_published', 1)->where('user_id', '!=', auth()->user()->id);

        $article = $article->firstOrFail();
        $user = User::findOrFail(auth()->user()->id);

        if ($user->balance >= $article->price) {

            $article->user_investments()->create([
                'user_id' => $user->id,
                'author_id' => $article->user_id,
                'amount' => $article->price,
            ]);

            $this->addToBondingCurve($user, $article->user_id, $article->price);

            // Make is_paid column true of all 8 level parents of current user
            $articleShare = ArticleShare::where('article_id', $article->id)->where('referee_id', auth()->user()->id)->first();
            if ($articleShare) {

                $maxSharesLimit = config('website.max_article_shares');
                $maxLevelsLimit = $maxSharesLimit;

                $cte = DB::select("WITH RECURSIVE
                cte AS ( (SELECT id, referee_id, referrer_id, article_id, 1 lvl
                        FROM article_share
                        WHERE referee_id = " . auth()->user()->id . " AND article_id = " . $article->id . ")
                    UNION ALL
                        (SELECT t.id, t.referee_id, t.referrer_id, cte.article_id, cte.lvl + 1
                        FROM cte
                        INNER JOIN article_share t ON cte.referrer_id = t.referee_id
                    WHERE cte.lvl <= " . $maxLevelsLimit . "-1  AND cte.article_id = " . $article->id . ") )
                SELECT id FROM cte;");

                $shareIds = [];
                foreach ($cte as $ct) {
                    array_push($shareIds, $ct->id);
                }

                if (count($shareIds)) {
                    ArticleShare::whereIn('id', $shareIds)->update([
                        'is_paid' => 1
                    ]);
                }
            }

            SendUserNotification::dispatch(['text' => '<a :to="{ name: \'profile\', params: { userId: ' . $user->id . ' } }"><b>' . $user->name . '</b></a> just invested <b>' . $article->price . ' CPHR</b>.', 'user_id' => $article->user_id]);
        } else {
            return $this->sendError(['balance' => 'You dont have enough balance!'], true);
        }

        return $this->sendResponse([]);
    }

    /**Upvote
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function upvote(Request $request)
    {

        $this->validate_fields($request, ['amount' => ['required', 'numeric', 'gt:0']]);

        // if ($request->user_id != auth()->user()->id) {
        $user = User::findOrFail($request->user_id);
        $auth = User::findOrFail(auth()->user()->id);

        if ($auth->balance >= $request->amount) {

            $user->user_investments()->create([
                'user_id' => $auth->id,
                'author_id' => $user->id,
                'amount' => $request->amount,
            ]);

            $this->addToBondingCurve($auth, $user->id, $request->amount);

            SendUserNotification::dispatch(['text' => '<a :to="{ name: \'profile\', params: { userId: ' . $auth->id . ' } }"><b>' . $auth->name . '</b></a> just invested <b>' . $request->amount . ' CPHR</b>.', 'user_id' => $user->id]);
        } else {
            return $this->sendError(['balance' => 'You dont have enough balance!'], true);
        }

        return $this->sendResponse($this->get_other_user_investments($request, false));
        // }
    }

    /**Cashout
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function cashout(Request $request)
    {
        $this->validate_fields($request, ['amount' => ['required', 'numeric', 'gt:0']]);

        $user = User::findOrFail($request->user_id);
        $auth = User::findOrFail(auth()->user()->id);

        $bondingCurve = BondingCurve::where('author_id', $user->id)->where('user_id', $auth->id)->first();

        if ($bondingCurve) {
            if ($bondingCurve->total_investments >= $request->amount) {
                $bondingCurve->total_investments -= $request->amount;
                $bondingCurve->update();

                // Deduct amount from user balance
                $auth->balance += $request->amount;
                $auth->update();

                // Add 90% amount to author
                // $user->balance += (90 / 100) * $request->amount;
                // $user->update();

                // Split 10% amount to all holders proportionally

            } else {
                return $this->sendError(['balance' => 'You dont have enough cash!'], true);
            }

            return $this->sendResponse($this->get_other_user_investments($request, false));
        } else {
            return $this->sendError(['balance' => 'You dont have any investment to this author!'], true);
        }
    }

    /**Update bonding curve
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function addToBondingCurve($authUser, $user_id, $amount)
    {
        $bondingCurve = BondingCurve::where('author_id', $user_id)->where('user_id', $authUser->id)->first();
        if ($bondingCurve) {
            $bondingCurve->total_investments += $amount;
            $bondingCurve->update();
        } else {
            $bondingCurve = new BondingCurve();
            $bondingCurve->total_investments = $amount;
            $bondingCurve->user_id = $authUser->id;
            $bondingCurve->author_id = $user_id;
            $bondingCurve->save();
        }

        // Deduct amount from user balance
        $authUser->balance -= $amount;
        $authUser->update();

        // // Add 90% amount to article owner
        // $articleOwner = User::findOrFail($user_id);
        // $articleOwner->balance += (90 / 100) * $amount;
        // $articleOwner->update();

        // Split 10% amount to all holders proportionally
        return true;
    }

    /**Read an article
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function read_article(Request $request)
    {
        $article = Article::where('id', $request->article_id)->where('is_published', 1);
        $article = $article->first();

        if ($article) {
            if (auth()->user()->id != $article->user_id) {

                if (!$article->total_reads(auth()->user()->id)->count()) {
                    $article->total_reads()->attach([auth()->user()->id]);
                    return true;
                }
            }
        }

        return false;
    }

    /**Share an article
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function share_article(Request $request)
    {

        if ($request->referral_token) {
            $user = User::where('referral_token', $request->referral_token)->where('id', '!=', auth()->user()->id)->first();
            $article = Article::where('id', $request->article_id)->where('is_published', 1)->where('user_id', '!=', auth()->user()->id)->first();

            if ($user && $article && $article->share_to_read) {
                if (!$article->total_shares(auth()->user()->id)->count()) {
                    $article->total_shares()->attach([auth()->user()->id => ['referrer_id' => $user->id, 'is_paid' => 0]]);
                    return true;
                }
            }
        }

        return false;
    }

    /** Check If Referrals Paid for an article
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function isArticlePaidByReferrals(Request $request)
    {

        $maxSharesLimit = config('website.max_article_shares');
        $maxDirectChildsLimit = $maxSharesLimit;
        // maxLevelsLimit applys on recursion part in resursive query (After UNION ALL). It means first level will always return even if you set it 0
        $maxLevelsLimit = $maxSharesLimit;

        $isAlreadyPaidByReferral = false;

        $cte = DB::select("WITH RECURSIVE
            cte AS ( (SELECT referee_id, referrer_id, article_id, 1 lvl
                    FROM article_share
                    WHERE referrer_id = " . auth()->user()->id . " AND article_id = " . $request->article_id . " LIMIT " . $maxDirectChildsLimit . ")
                UNION ALL
                    (SELECT t.referee_id, t.referrer_id, cte.article_id, cte.lvl + 1
                    FROM cte
                    INNER JOIN article_share t ON cte.referee_id = t.referrer_id
                WHERE cte.lvl <= " . $maxLevelsLimit . "-1  AND cte.article_id = " . $request->article_id . " LIMIT " . $maxDirectChildsLimit . ") )
            SELECT * FROM cte;");

        $refereesIds = [];
        foreach ($cte as $ct) {
            array_push($refereesIds, $ct->referrer_id);
            array_push($refereesIds, $ct->referee_id);
        }
        $refereesIds = array_unique($refereesIds);

        // $refereesIds = array_merge($refereesIds, array_column($cte, 'referee_id'));
        // $refereesIds = array_merge($refereesIds, array_column($cte, 'referrer_id'));
        // $refereesIds = array_unique($refereesIds);

        $isAlreadyPaidByReferral = UserInvestment::whereIn('user_id', $refereesIds)->where('user_investmentable_type', Article::class)->where('user_investmentable_id', $request->article_id)->exists();

        return $isAlreadyPaidByReferral;

        // $refereesIds = [];
        // if (count($articleShares)) {

        //     foreach ($articleShares as $articleShare) {
        //         array_push($refereesIds, $articleShare->referee_id);
        //         $refereesIds = array_merge($refereesIds, $this->getRefereeIdsAttribute($articleShare->id, $articleShare->referee_id, $request->article_id));
        //     }
        // }

        // $refereesIds = array_unique($refereesIds);

        // $articleShares = ArticleShare::where('referrer_id', auth()->user()->id)->where('article_id', $request->article_id)->orderBy('id', 'ASC')->limit($maxLevelsLimit)->get();
        // $refereesIds = $articleShares->pluck('referee_id')->toArray();

        // $isAlreadyPaidByReferral = ArticleUserPaid::whereIn('user_id', $refereesIds)->where('article_id', $request->article_id)->exists();

        // return $isAlreadyPaidByReferral;
    }

    // public function getRefereeIdsAttribute($id, $referrerId, $articleId)
    // {
    //     $referees = [];

    //     $articleShare = ArticleShare::where('id','>',$id)->where('referrer_id', $referrerId)->where('article_id', $articleId)->first();

    //     while (!is_null($articleShare) && count($referees) != config('website.max_article_shares')) {
    //         array_push($referees, $articleShare->referee_id);
    //         $articleShare = ArticleShare::where('id','>',$articleShare->id)->where('referrer_id', $articleShare->referee_id)->where('article_id', $articleId)->first();
    //     }

    //     // $referees = array_reverse($referees);
    //     // $referees = array_slice($referees, 0, 8);

    //     return $referees;
    // }

    /** Check If article is free to view
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function isArticleFree(Request $request, $article)
    {
        $isArticleFree = false;

        // Check for Liquidation days
        $liquidation_days = $this->getLiquidationDays($article);

        // article is free for its author/creator
        if (auth()->user()->id == $article->user_id) {
            return [true, $liquidation_days];
        }

        if ($liquidation_days) {
            // Check if user himself paid the article
            $isArticleFree = UserInvestment::where('user_id', auth()->user()->id)->where('user_investmentable_type', Article::class)->where('user_investmentable_id', $request->article_id)->exists();

            // If user didnot pay then check If referrals paid
            if (!$isArticleFree) $isArticleFree = ArticleShare::where('article_id', $request->article_id)->where('is_paid', true)->where('referrer_id', auth()->user()->id)->orWhere('referee_id', auth()->user()->id)->exists();
            // if (!$isArticleFree) $isArticleFree = $this->isArticlePaidByReferrals($request);
        } else {
            $isArticleFree = true;
        }

        return [$isArticleFree, $liquidation_days];
    }

    public function getLiquidationDays($article)
    {

        $current_time = Carbon::now();
        $posted_time_after_days = Carbon::createFromFormat('Y-m-d H:i:s', $article->date_posted)->addDays($article->liquidation_days);

        $liquidation_days = ceil(
            ($posted_time_after_days->valueOf() - $current_time->valueOf()) /
                (1000 * 3600 * 24)
        );

        return $liquidation_days = $liquidation_days < 0 ? 0 : $liquidation_days;
    }

    /** Get other user investments
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_other_user_investments(Request $request, $shouldReturnResponse = true)
    {
        User::findOrFail($request->user_id);

        // $userStats = DB::select(DB::raw('SELECT author_id,
        // COALESCE(((SELECT SUM(x.amt) FROM (SELECT amount as amt,author_id FROM user_profile_investments UNION ALL SELECT price as amt,author_id FROM article_user_paids) as x WHERE x.author_id = t.author_id)), 0) as total_investments,
        // COALESCE(((SELECT COUNT(DISTINCT x.user_id) FROM (SELECT user_id,author_id FROM user_profile_investments UNION SELECT user_id,author_id FROM article_user_paids) as x WHERE x.author_id = t.author_id)), 0) as total_investors,
        // COALESCE(((SELECT SUM(x.amt) FROM (SELECT amount as amt,author_id,user_id FROM user_profile_investments UNION ALL SELECT price as amt,author_id,user_id FROM article_user_paids) as x WHERE x.author_id = t.author_id AND x.user_id = ' . auth()->user()->id .
        //     ')), 0) as user_total_investments
        // FROM (SELECT author_id, user_id FROM user_profile_investments UNION ALL SELECT author_id, user_id FROM article_user_paids ) t WHERE author_id = ' . $request->user_id . ' LIMIT 1'));

        $userStats = DB::select(DB::raw('SELECT author_id,
        COALESCE(((SELECT SUM(x.amt) FROM (SELECT total_investments as amt,author_id FROM bonding_curves) as x WHERE x.author_id = t.author_id)), 0) as total_investments,
        COALESCE(((SELECT COUNT(DISTINCT x.user_id) FROM (SELECT user_id,author_id FROM bonding_curves) as x WHERE x.author_id = t.author_id)), 0) as total_investors,
        COALESCE(((SELECT SUM(x.amt) FROM (SELECT total_investments as amt,author_id,user_id FROM bonding_curves) as x WHERE x.author_id = t.author_id AND x.user_id = ' . auth()->user()->id .
            ')), 0) as user_total_investments
        FROM bonding_curves t WHERE author_id = ' . $request->user_id . ' LIMIT 1'));

        $userStats = count($userStats) ? $userStats[0] : null;

        if ($userStats) {
            $userStats->total_stakes = 0;
            if ($userStats->total_investments) {
                $userStats->total_stakes = round((($userStats->user_total_investments / $userStats->total_investments) * 100), config('website.total_stakes_decimal_points'));
            }
        } else {
            $userStats = (object) [];
            $userStats->total_investments = 0;
            $userStats->total_investors = 0;
            $userStats->user_total_investments = 0;
            $userStats->total_stakes = 0;
        }

        if ($shouldReturnResponse) return $this->sendResponse($userStats);
        else return $userStats;
    }

    /** Get user investments
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_user_investments(Request $request)
    {

        // $userStats = DB::select(DB::raw('SELECT author_id, user_id, t.created_at, users.id as id,
        // users.name as title, users.bg as image_url, users.pfp as pfp,
        // COALESCE(((SELECT SUM(x.amt) FROM (SELECT amount as amt,author_id FROM user_profile_investments UNION ALL SELECT price as amt,author_id FROM article_user_paids) as x WHERE x.author_id = t.author_id)), 0) as total_investments,
        // COALESCE(((SELECT SUM(x.amt) FROM (SELECT amount as amt,author_id,user_id FROM user_profile_investments UNION ALL SELECT price as amt,author_id,user_id FROM article_user_paids) as x WHERE x.author_id = t.author_id AND x.user_id = ' . auth()->user()->id . ')), 0) as user_total_investments,
        // (select count(*) from `users` inner join `follows` on `follows`.`id` = `users`.`id` where t.author_id = `follows`.`followed_id`) as `user_followers_count`,
        // (select count(*) from `users` inner join `follows` on `follows`.`id` = `users`.`id` where t.author_id = `follows`.`follower_id`) as `user_followed_count`
        // FROM (SELECT author_id, user_id, created_at FROM user_profile_investments  UNION ALL SELECT author_id, user_id, created_at FROM article_user_paids ) t INNER JOIN users ON users.id = t.author_id AND user_id = ' . auth()->user()->id . '  GROUP BY author_id ORDER BY t.created_at DESC'));

        $userStats = DB::select(DB::raw('SELECT author_id, user_id, t.updated_at, users.id as id,
        users.name as title, users.bg as image_url, users.pfp as pfp,
        COALESCE(((SELECT SUM(x.amt) FROM (SELECT total_investments as amt,author_id FROM bonding_curves) as x WHERE x.author_id = t.author_id)), 0) as total_investments,
        COALESCE(((SELECT SUM(x.amt) FROM (SELECT total_investments as amt,author_id,user_id FROM bonding_curves) as x WHERE x.author_id = t.author_id AND x.user_id = ' . auth()->user()->id . ')), 0) as user_total_investments,
        (select count(*) from `users` inner join `follows` on `follows`.`id` = `users`.`id` where t.author_id = `follows`.`followed_id`) as `user_followers_count`,
        (select count(*) from `users` inner join `follows` on `follows`.`id` = `users`.`id` where t.author_id = `follows`.`follower_id`) as `user_followed_count`
        FROM bonding_curves t INNER JOIN users ON users.id = t.author_id AND user_id = ' . auth()->user()->id . '  ORDER BY t.updated_at DESC'));

        foreach ($userStats as $userStat) {

            $userStat->total_stakes = round(($userStat->user_total_investments / $userStat->total_investments) * 100, config('website.total_stakes_decimal_points'));

            $userStat->user = (object) [
                'id' => $userStat->id,
                'image_url' => $userStat->image_url,
                'pfp' => $userStat->pfp,
            ];

            unset($userStat->id);
            unset($userStat->bg);
            unset($userStat->pfp);
        }

        return $this->sendResponse($userStats);
    }

    /**
     * Get edit user profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function get_edit_user_profile(Request $request)
    {
        $user = User::select('id', 'name', 'bio', 'pfp', 'email', 'referral_token')->where('id', auth()->user()->id)->firstOrFail();

        return $this->sendResponse($user);
    }

    /**
     * Update user profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function update_user_profile(Request $request)
    {
        $rules = User::rules();
        $rules['bio'][0] = 'required';
        $rules['password'][0] = 'nullable';
        $rules['email'][4] = 'unique:users,email,' . auth()->user()->id;

        $fields = $this->validate_fields($request, $rules);

        $user = User::findOrFail(auth()->user()->id);
        $ouser = clone $user;

        $fields = User::storeFiles($request, $fields, $ouser);

        $user->name = $fields['name'];
        if ($fields['password']) $user->password = bcrypt($fields['password']);
        if ($fields['pfp']) $user->pfp = $fields['pfp'];
        $user->email = $fields['email'];
        $user->bio = $fields['bio'];
        $user->update();

        User::deleteFiles($ouser, $request);

        return $this->sendResponse([]);
    }

    /**
     * signup.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function signup(Request $request)
    {
        $rules = User::rules();
        $fields = $this->validate_fields($request, $rules);

        $user = new User();
        $user->name = $fields['name'];
        $user->password = bcrypt($fields['password']);
        $user->email = $fields['email'];
        $user->balance = config('website.balance');
        $user->referral_token = generateReferral();
        $user->save();

        SendUserNotification::dispatch(['text' => 'Welcome to Cypher! <a :to="{ name: \'drafts\' }"><b>Get started here</b></a>.', 'user_id' => $user->id]);

        return $this->sendResponse(['id' => $user->id, 'name' => $user->name, 'token' => $user->createToken('API Token')->plainTextToken]);
    }

    /**
     * signin.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function signin(Request $request)
    {
        $rules = User::rules();
        $rules['name'][0] = 'nullable';
        $rules['email'][4] = '';
        $fields = $this->validate_fields($request, $rules);

        if (!Auth::attempt($fields)) {
            return $this->sendError(['error' => 'Credentials do\'not match!'], false, 401);
        }

        return $this->sendResponse(['id' => auth()->user()->id, 'name' => auth()->user()->name, 'token' => auth()->user()->createToken('API Token')->plainTextToken]);
    }

    /**
     * logout.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        $request->user()->currentAccessToken()->delete();

        return $this->sendResponse([]);
    }

    /**
     * Send Token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function send_token(Request $request)
    {
        $rules = [
            'cphr' => ['required', 'numeric', 'gt:0'],
            'receiver' => ['required', 'email', 'exists:users,email']
        ];

        $fields = $this->validate_fields($request, $rules);

        $auth = User::findOrFail(auth()->user()->id);
        $user = User::where('email', $fields['receiver'])->firstOrFail();

        if ($auth->balance >= $fields['cphr']) {
            $auth->balance -= $fields['cphr'];
            $auth->update();

            $user->balance += $fields['cphr'];
            $user->update();

            SendUserNotification::dispatch(['text' => '<b>' . $auth->name . '</b> sent <b>' . $fields['cphr'] . ' CPHR</b>.', 'user_id' => $user->id]);
        } else {
            return $this->sendError(['balance' => 'You dont have enough balance!'], true);
        }

        return $this->sendResponse([]);
    }

    /**
     * Block User.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function block_user(Request $request)
    {
        $rules = [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];

        $fields = $this->validate_fields($request, $rules);

        $user = User::findOrFail($fields['user_id']);
        if (auth()->user()->block_user($user->id)->exists()) {
            auth()->user()->block_user()->detach($user->id);
        } else {
            auth()->user()->block_user()->attach($user->id);
        }


        return $this->sendResponse([]);
    }

    /**
     * Send notification.
     *
     */

    // public function sendNotification($msg, $userId)
    // {
    //     $notification = new Notification();
    //     $notification->text = $msg;
    //     $notification->save();

    //     $userNotification = new UserNotification();
    //     $userNotification->notification_id = $notification->id;
    //     $userNotification->user_id = $userId;
    //     $userNotification->save();
    // }

    public function getLiquidationDaysQuery()
    {
        return DB::raw('CEIL( (UNIX_TIMESTAMP(DATE_ADD(date_posted, INTERVAL liquidation_days DAY)) - UNIX_TIMESTAMP(NOW(3))) / (1000 * 3600 * 24) ) as remaining_liquidation_days');
    }

    /**
     * facebook share.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function facebookshare(Request $request, $article_id, $user_id, $version)
    {
        $article = Article::where('id', $article_id)->where('is_published', 1)->firstOrFail();
        $user = User::findOrFail($user_id);

        $ref = \Request::server('HTTP_REFERER');
        if ((strpos($ref, 'l.facebook') > -1) || (strpos($ref, 'lm.facebook') > -1)) {
            return redirect()->to(env('VUE_URL') . '/article/' . $article_id . '/' . $user->referral_token);
        }

        return view('front.facebookshare', ['article' => $article, 'user' => $user, 'v' => $version]);
    }

    /**
     * validate  fields.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function validate_fields($request, $validations)
    {

        $input = $request->all();

        $validator = Validator::make($input, $validations);

        if ($validator->fails()) {
            return $this->sendError($validator->errors()->all(), true);
        }

        return $validator->validated();
    }
}
