require 'net/http'
require 'railsless-deploy'

set :application, "warpdrive"
set :repository,  "git@github.com:Savvii/warpdrive.git"
set :branch, "master"

# set :scm, :git # You can set :scm explicitly or Capistrano will make an intelligent guess based on known version control directory names
# Or: `accurev`, `bzr`, `cvs`, `darcs`, `git`, `mercurial`, `perforce`, `subversion` or `none`

set :gateway, "chef-server.savviihq.com"
role :web, "nginx" # Your HTTP server, Apache/etc
role :app, "nginx" # This may be the same as your `Web` server

set :deploy_to, "/opt/savvii/#{application}"

default_run_options[:pty] = true
set :user, "deploy"
set :ssh_options, { :forward_agent => true }
set :use_sudo, false

# if you want to clean up old releases on each deploy uncomment this:
after "deploy:symlink", "deploy:cleanup"

# if you're still using the script/reaper helper you will need
# these http://github.com/rails/irs_process_scripts

# If you are using Passenger mod_rails uncomment this:
# namespace :deploy do
#   task :start do ; end
#   task :stop do ; end
#   task :restart, :roles => :app, :except => { :no_release => true } do
#     run "#{try_sudo} touch #{File.join(current_path,'tmp','restart.txt')}"
#   end
# end

###########################################################################
#                                Campfire                                 #
###########################################################################
namespace :deploy do
  def campfire_message(message)
    room_id = "570672"
    token   = "b0fb1a3fbdf18570ff87863176b2f9e7f16b37ab"
    begin
      request = Net::HTTP::Post.new("/room/#{room_id}/speak.json", 'Content-Type' => 'application/json')
      request.body = "{\"message\":{\"type\":\"TextMessage\",\"body\":\"#{message}\"}}"
      request.basic_auth token, 'x'
      http = Net::HTTP.new("berkes.campfirenow.com", 443)
      http.use_ssl = true
      http.request(request)
    rescue Exception => e
      puts e.message
    end
  end
  task :notify_start do
    user_name_is = `git config user.name` + ' is'
    message = ":shipit: [#{application}] #{user_name_is.gsub("\n", '')} deploying #{branch}"
    campfire_message(message)
  end

  task :notify_end do
    user_name_is = `git config user.name` + ' is'
    message = ":tada: [#{application}] #{user_name_is.gsub("\n", '')} finished deploying #{branch}"
    campfire_message(message)
  end
end

before 'deploy:update_code', "deploy:notify_start"
after 'deploy:symlink', 'deploy:notify_end'
