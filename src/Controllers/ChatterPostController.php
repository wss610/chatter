<?php

namespace DevDojo\Chatter\Controllers;

use DevDojo\Chatter\Models\Models;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as Controller;
use Auth;
use Carbon\Carbon;
use Validator;

class ChatterPostController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $total = 10;
        $offset = 0;
        if($request->total){
            $total = $request->total;
        }
        if($request->offset){
            $offset = $request->offset;
        }
        $posts = Models::post()->with('user')->orderBy('created_at', 'DESC')->take($total)->offset($offset)->get();
        return response()->json($posts);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $stripped_tags_body = array('body' => strip_tags($request->body));
        $validator = Validator::make($stripped_tags_body, [
            'body' => 'required|min:10',
        ]);

        if(function_exists('chatter_before_new_response')){
          chatter_before_new_response($request, $validator);
        }

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        if(config('chatter.security.limit_time_between_posts')){

            if($this->notEnoughTimeBetweenPosts()){
                $minute_copy = (config('chatter.security.time_between_posts') == 1) ? ' minute' : ' minutes';
                $chatter_alert = array(
                    'chatter_alert_type' => 'danger',
                    'chatter_alert' => 'In order to prevent spam, Please allow at least ' . config('chatter.security.time_between_posts') . $minute_copy . ' inbetween submitting content.'
                    );
                return back()->with($chatter_alert)->withInput();
            }
        }

        $request->request->add(['user_id' => Auth::user()->id]);
        $new_post = Models::post()->create($request->all());
        
        $discussion = Models::discussion()->find($request->chatter_discussion_id);

        $category = Models::category()->find($discussion->chatter_category_id);
        if(!isset($category->slug)){
          $category = Models::category()->first();
        }

        if($new_post->id){
            if(function_exists('chatter_after_new_response')){
              chatter_after_new_response($request);
            }
            $chatter_alert = array(
                'chatter_alert_type' => 'success',
                'chatter_alert' => 'Response successfully submitted to ' . config('chatter.titles.discussion') . '.'
                );
            return redirect('/' . config('chatter.routes.home') . '/' . config('chatter.routes.discussion') . '/' . $category->slug . '/'  . $discussion->slug)->with($chatter_alert);
        } else {
            $chatter_alert = array(
                'chatter_alert_type' => 'danger',
                'chatter_alert' => 'Sorry, there seems to have been a problem submitting your response.'
                );
            return redirect('/' . config('chatter.routes.home') . '/' . config('chatter.routes.discussion') . '/' . $category->slug . '/' . $discussion->slug)->with($chatter_alert);
        }   
    }


    private function notEnoughTimeBetweenPosts(){
        $user = Auth::user();

        $past = Carbon::now()->subMinutes(config('chatter.security.time_between_posts'));

        $last_post = Models::post()->where('user_id', '=', $user->id)->where('created_at', '>=', $past)->first();

        if(isset($last_post)){
            return true;
        }

        return false;
    }


    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $stripped_tags_body = array('body' => strip_tags($request->body));
        $validator = Validator::make($stripped_tags_body, [
            'body' => 'required|min:10',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $post = Models::post()->find($id);
        if(!Auth::guest() && (Auth::user()->id == $post->user_id)){
            $post->body = $request->body;
            $post->save();

            $discussion = Models::discussion()->find($post->chatter_discussion_id);

            $category = Models::category()->find($discussion->chatter_category_id);
            if(!isset($category->slug)){
              $category = Models::category()->first();
            }

            $chatter_alert = array(
                'chatter_alert_type' => 'success',
                'chatter_alert' => 'Successfully updated the ' . config('chatter.titles.discussion') . '.'
                );
            return redirect('/' . config('chatter.routes.home') . '/' . config('chatter.routes.discussion') . '/' . $category->slug . '/' . $discussion->slug)->with($chatter_alert);

        } else {

            $chatter_alert = array(
                'chatter_alert_type' => 'danger',
                'chatter_alert' => 'Nah ah ah... Could not update your response. Make sure you\'re not doing anything shady.'
                );
            return redirect('/' . config('chatter.routes.home'))->with($chatter_alert);

        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $post = Models::post()->find($id);
        if(!Auth::guest() && (Auth::user()->id == $post->user_id)){
            $post->delete();

            $count_post = Models::post()->where('chatter_discussion_id',$post->chatter_discussion_id)->count();
            $discussion = Models::discussion()->find($post->chatter_discussion_id);

            // if there are no more posts, delete the discussion as well
            if($count_post <= 0){

                Models::discussion()->find($post->chatter_discussion_id)->delete();

                $chatter_alert = array(
                    'chatter_alert_type' => 'success',
                    'chatter_alert' => 'Successfully deleted response and ' . strtolower(config('chatter.titles.discussion')) . '.'
                );
                return redirect('/' . config('chatter.routes.home') )->with($chatter_alert);

            } else {

                $chatter_alert = array(
                    'chatter_alert_type' => 'success',
                    'chatter_alert' => 'Successfully deleted response from the ' . config('chatter.titles.discussion') . '.'
                );
                return redirect('/' . config('chatter.routes.home') . '/' . config('chatter.routes.discussion') . '/' . $discussion->slug)->with($chatter_alert);

            }

        } else {

            $chatter_alert = array(
                'chatter_alert_type' => 'danger',
                'chatter_alert' => 'Nah ah ah... Could not delete the response. Make sure you\'re not doing anything shady.'
                );
            return redirect('/' . config('chatter.routes.home'))->with($chatter_alert);

        }
    }
}
